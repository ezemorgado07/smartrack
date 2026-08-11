<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

require_once 'auth.php';
require_once 'dbconn.php';
requerir_login();

ob_clean();
header('Content-Type: application/json');

$uid = (int) $_SESSION['usuario_id'];

// ── Leer codigo_pdu opcional (GET o POST) ────────────────────
$codigo_pdu_param = '';
if (!empty($_POST['codigo_pdu']) && is_string($_POST['codigo_pdu'])) {
    $codigo_pdu_param = trim($_POST['codigo_pdu']);
} elseif (!empty($_GET['codigo_pdu']) && is_string($_GET['codigo_pdu'])) {
    $codigo_pdu_param = trim($_GET['codigo_pdu']);
}

// ── Resolver qué PDU usar ─────────────────────────────────────
if (!empty($codigo_pdu_param)) {
    // Vino un codigo_pdu explícito — validar que le pertenezca al usuario
    $param_sql = mysqli_real_escape_string($conex, $codigo_pdu_param);

    $pdu_res = mysqli_query($conex,
        "SELECT p.id, p.codigo_pdu, p.modo, p.activo, p.ultimo_contacto,
                l.fecha_vencimiento, l.fecha_fin_gracia
         FROM pdus p
         LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado IN ('activa','vencida')
         WHERE p.codigo_pdu = '$param_sql'
           AND p.activo = 1
           AND p.user_id = $uid
         LIMIT 1");

    $pdu = mysqli_fetch_assoc($pdu_res);

    // Si no le pertenece, intentar fallback por codigo_pdu en users
    if (!$pdu) {
        // Rechazar: el PDU pedido no existe o no le pertenece al usuario
        echo json_encode([
            'success' => false,
            'error'   => 'PDU no encontrado o no autorizado.'
        ]);
        mysqli_close($conex);
        exit();
    }

    $cod_sql = $param_sql;

} else {
    // Sin parámetro — comportamiento original:
    // 1. Buscar por users.codigo_pdu
    // 2. Si no hay, primer PDU del usuario por user_id
    // 3. Si no hay, primer PDU activo (fallback dev)
    $usr_res = mysqli_query($conex,
        "SELECT codigo_pdu FROM users WHERE id = $uid LIMIT 1");
    $usr_row = mysqli_fetch_assoc($usr_res);

    if (!empty($usr_row['codigo_pdu'])) {
        $cod_sql = mysqli_real_escape_string($conex, $usr_row['codigo_pdu']);
        $pdu_res = mysqli_query($conex,
            "SELECT p.id, p.codigo_pdu, p.modo, p.activo, p.ultimo_contacto,
                    l.fecha_vencimiento, l.fecha_fin_gracia
             FROM pdus p
             LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado IN ('activa','vencida')
             WHERE p.codigo_pdu = '$cod_sql' AND p.activo = 1
             LIMIT 1");
    } else {
        // Intentar por user_id en pdus
        $pdu_res = mysqli_query($conex,
            "SELECT p.id, p.codigo_pdu, p.modo, p.activo, p.ultimo_contacto,
                    l.fecha_vencimiento, l.fecha_fin_gracia
             FROM pdus p
             LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado IN ('activa','vencida')
             WHERE p.user_id = $uid AND p.activo = 1
             ORDER BY p.id ASC
             LIMIT 1");
    }

    $pdu = mysqli_fetch_assoc($pdu_res);

    // Fallback final: primer PDU activo del sistema (entorno dev)
    if (!$pdu) {
        $pdu_res = mysqli_query($conex,
            "SELECT p.id, p.codigo_pdu, p.modo, p.activo, p.ultimo_contacto,
                    l.fecha_vencimiento, l.fecha_fin_gracia
             FROM pdus p
             LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado IN ('activa','vencida')
             WHERE p.activo = 1
             ORDER BY p.id ASC
             LIMIT 1");
        $pdu = mysqli_fetch_assoc($pdu_res);
    }

    if (!$pdu) {
        echo json_encode([
            'success' => false,
            'error'   => 'No hay un PDU activo vinculado a este usuario.'
        ]);
        mysqli_close($conex);
        exit();
    }

    $cod_sql = mysqli_real_escape_string($conex, $pdu['codigo_pdu']);
}

$device_id  = (int) $pdu['id'];
$codigo_pdu = $pdu['codigo_pdu'];
$modo       = $pdu['modo'];

// ── Modo GRACIA ───────────────────────────────────────────────
// El hardware sigue enviando datos pero el cliente no los ve.
// El dashboard recibe modo='normal' + en_gracia=true + dias restantes.
if ($modo === 'gracia') {
    $dias_gracia = 0;
    if (!empty($pdu['fecha_fin_gracia'])) {
        $dias_gracia = max(0, (int) ceil(
            (strtotime($pdu['fecha_fin_gracia']) - strtotime('today')) / 86400
        ));
    }

    // Obtener tomas (siempre disponibles)
    $outlets_res = mysqli_query($conex,
        "SELECT outlet_number, label, is_on, is_locked
         FROM outlets WHERE codigo_pdu = '$cod_sql' ORDER BY outlet_number ASC");
    $outlets = [];
    while ($row = mysqli_fetch_assoc($outlets_res)) {
        $outlets[] = [
            'outlet_number' => (int) $row['outlet_number'],
            'label'         => $row['label'],
            'is_on'         => (int) $row['is_on'],
            'is_locked'     => (int) $row['is_locked']
        ];
    }
    $online = !empty($pdu['ultimo_contacto'])
        && (time() - strtotime($pdu['ultimo_contacto'])) <= 30;

    mysqli_close($conex);
    echo json_encode([
        'success'              => true,
        'modo'                 => 'normal',   // dashboard muestra banner normal
        'en_gracia'            => true,
        'dias_gracia_restantes'=> $dias_gracia,
        'online'               => $online,
        'codigo_pdu'           => $codigo_pdu,
        'outlets'              => $outlets
    ]);
    exit();
}

// ── Verificar licencia premium vigente ───────────────────────
$licencia_vigente = false;
if ($modo === 'premium' && !empty($pdu['fecha_vencimiento'])) {
    $licencia_vigente = strtotime($pdu['fecha_vencimiento']) >= strtotime('today');
    if (!$licencia_vigente) {
        // No degradar aquí — el cron se encarga. Solo reportar como normal.
        $modo = 'normal';
    }
}

// ── Estado de tomas ───────────────────────────────────────────
$outlets_res = mysqli_query($conex,
    "SELECT outlet_number, label, is_on, is_locked
     FROM outlets
     WHERE codigo_pdu = '$cod_sql'
     ORDER BY outlet_number ASC");

$outlets = [];
while ($row = mysqli_fetch_assoc($outlets_res)) {
    $outlets[] = [
        'outlet_number' => (int) $row['outlet_number'],
        'label'         => $row['label'],
        'is_on'         => (int) $row['is_on'],
        'is_locked'     => (int) $row['is_locked']
    ];
}

// ── Online/Offline ────────────────────────────────────────────
$online = false;
if (!empty($pdu['ultimo_contacto'])) {
    $online = (time() - strtotime($pdu['ultimo_contacto'])) <= 30;
}

// ── Modo NORMAL ───────────────────────────────────────────────
if ($modo === 'normal' || !$licencia_vigente) {
    mysqli_close($conex);
    echo json_encode([
        'success'    => true,
        'modo'       => 'normal',
        'online'     => $online,
        'codigo_pdu' => $codigo_pdu,
        'outlets'    => $outlets
    ]);
    exit();
}

// ── Modo PREMIUM: PZEM ───────────────────────────────────────
$tel_res = mysqli_query($conex,
    "SELECT voltage_v, current_a, power_w, power_factor,
            frequency_hz, energy_kwh, reading_timestamp, is_buffered
     FROM telemetry_pzem
     WHERE codigo_pdu = '$cod_sql'
     ORDER BY reading_timestamp DESC
     LIMIT 1");

$telemetry = mysqli_fetch_assoc($tel_res);

// ── Modo PREMIUM: AHT10 ──────────────────────────────────────
$aht_res = mysqli_query($conex,
    "SELECT temperature_c, humidity_pct,
            reading_timestamp AS aht_ts,
            is_buffered AS aht_buffered
     FROM telemetry_aht10
     WHERE codigo_pdu = '$cod_sql'
     ORDER BY reading_timestamp DESC
     LIMIT 1");

$telemetry_aht10 = mysqli_fetch_assoc($aht_res);

// ── Alertas activas del PDU ───────────────────────────────────
$alertas_res = mysqli_query($conex,
    "SELECT id, tipo, valor_detectado, umbral_configurado, created_at
     FROM alertas
     WHERE codigo_pdu = '$cod_sql' AND estado = 'activa'
     ORDER BY created_at DESC");

$labels_alerta = [
    'temperature_c' => 'Temperatura del rack superó el umbral máximo',
    'humidity_pct'  => 'Humedad del rack fuera de rango',
    'current_a'     => 'Corriente superó el umbral máximo',
    'power_w'       => 'Potencia superó el umbral máximo',
    'frequency_hz'  => 'Frecuencia de red fuera de rango',
    'voltage_v'     => 'Voltaje fuera de rango',
];

$alertas_activas = [];
while ($al = mysqli_fetch_assoc($alertas_res)) {
    $alertas_activas[] = [
        'id'                 => (int)   $al['id'],
        'tipo'               => $al['tipo'],
        'mensaje'            => $labels_alerta[$al['tipo']] ?? $al['tipo'],
        'valor_detectado'    => (float) $al['valor_detectado'],
        'umbral_configurado' => (float) $al['umbral_configurado'],
        'created_at'         => $al['created_at'],
    ];
}

mysqli_close($conex);

if (!$telemetry) {
    echo json_encode([
        'success'         => true,
        'modo'            => 'premium',
        'online'          => $online,
        'codigo_pdu'      => $codigo_pdu,
        'outlets'         => $outlets,
        'telemetry'       => null,
        'telemetry_aht10' => $telemetry_aht10 ? [
            'temperature_c'     => (float) $telemetry_aht10['temperature_c'],
            'humidity_pct'      => (float) $telemetry_aht10['humidity_pct'],
            'reading_timestamp' => $telemetry_aht10['aht_ts'],
            'is_buffered'       => (int)   $telemetry_aht10['aht_buffered']
        ] : null,
        'alertas_activas' => $alertas_activas,
    ]);
    exit();
}

echo json_encode([
    'success'    => true,
    'modo'       => 'premium',
    'online'     => $online,
    'codigo_pdu' => $codigo_pdu,
    'outlets'    => $outlets,
    'telemetry'  => [
        'voltage_v'         => (float) $telemetry['voltage_v'],
        'current_a'         => (float) $telemetry['current_a'],
        'power_w'           => (float) $telemetry['power_w'],
        'power_factor'      => (float) $telemetry['power_factor'],
        'frequency_hz'      => (float) $telemetry['frequency_hz'],
        'energy_kwh'        => (float) $telemetry['energy_kwh'],
        'reading_timestamp' => $telemetry['reading_timestamp'],
        'is_buffered'       => (int)   $telemetry['is_buffered']
    ],
    'telemetry_aht10' => $telemetry_aht10 ? [
        'temperature_c'     => (float) $telemetry_aht10['temperature_c'],
        'humidity_pct'      => (float) $telemetry_aht10['humidity_pct'],
        'reading_timestamp' => $telemetry_aht10['aht_ts'],
        'is_buffered'       => (int)   $telemetry_aht10['aht_buffered']
    ] : null,
    'alertas_activas' => $alertas_activas,
]);
?>