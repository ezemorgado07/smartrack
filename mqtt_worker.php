<?php
// ============================================================
//  SmartRACK — mqtt_worker.php
//  Proceso continuo que escucha el broker MQTT y procesa
//  telemetría, estados de toma, alertas del firmware y LWT
//  de todos los PDUs.
//
//  NO es un endpoint web — se ejecuta desde la terminal:
//    php mqtt_worker.php
//
//  En producción, correr con supervisor/systemd para que
//  se reinicie automáticamente si el proceso muere.
//
//  No usa sesiones PHP ni autenticación web.
// ============================================================

// Prevenir ejecución vía web
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Este script solo puede ejecutarse desde la terminal.');
}

set_time_limit(0);

use PhpMqtt\Client\MqttClient as PhpMqttClient;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Exceptions\MqttClientException;
use Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/alertas_helper.php';

// ── Cargar .env ───────────────────────────────────────────────
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// ── Logger ────────────────────────────────────────────────────
$log_file = __DIR__ . '/mqtt_worker.log';

function wlog(string $msg): void {
    global $log_file;
    $linea = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($log_file, $linea, FILE_APPEND | LOCK_EX);
    echo $linea;
}

// ── Conexión DB ───────────────────────────────────────────────
function db_connect() {
    $host     = $_ENV['DB_HOST']  ?? 'localhost';
    $db_user  = $_ENV['DB_USER']  ?? 'root';
    $password = $_ENV['DB_PASS']  ?? '';
    $database = $_ENV['DB_NAME']  ?? 'smartrack';
    $db_port  = (int) ($_ENV['DB_PORT'] ?? 3306);

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $conex = mysqli_connect($host, $db_user, $password, $database, $db_port);
        mysqli_set_charset($conex, 'utf8mb4');
        return $conex;
    } catch (mysqli_sql_exception $e) {
        wlog('ERROR DB: ' . $e->getMessage());
        return null;
    }
}

$conex = db_connect();
if (!$conex) {
    wlog('No se pudo conectar a la DB. Abortando.');
    exit(1);
}

// ── Helpers ───────────────────────────────────────────────────

function pdu_premium_activo($conex, string $codigo_pdu): bool {
    $cod_sql = mysqli_real_escape_string($conex, $codigo_pdu);
    $res = mysqli_query($conex,
        "SELECT p.modo, l.fecha_vencimiento
         FROM pdus p
         LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado = 'activa'
         WHERE p.codigo_pdu = '$cod_sql' AND p.activo = 1
         LIMIT 1");
    $row = mysqli_fetch_assoc($res);
    if (!$row) return false;
    return $row['modo'] === 'premium'
        && !empty($row['fecha_vencimiento'])
        && strtotime($row['fecha_vencimiento']) >= strtotime('today');
}

function extraer_codigo_pdu(string $topic): ?string {
    if (preg_match('#^pdu/([^/]+)/#', $topic, $m)) {
        return $m[1];
    }
    return null;
}

// ── Procesar telemetría PZEM ──────────────────────────────────
function procesar_pzem($conex, string $codigo_pdu, array $data): void {
    if (!pdu_premium_activo($conex, $codigo_pdu)) {
        wlog("[PZEM] PDU $codigo_pdu sin premium activo — descartado");
        return;
    }

    $cod_sql = mysqli_real_escape_string($conex, $codigo_pdu);

    $campos = ['voltage_v','current_a','power_w','power_factor','frequency_hz','energy_kwh'];
    foreach ($campos as $c) {
        if (!isset($data[$c]) || !is_numeric($data[$c])) {
            wlog("[PZEM] Campo faltante/inválido: $c — descartado");
            return;
        }
    }

    $voltage = (float) $data['voltage_v'];
    $current = (float) $data['current_a'];
    $power   = (float) $data['power_w'];
    $pf      = (float) $data['power_factor'];
    $freq    = (float) $data['frequency_hz'];
    $energy  = (float) $data['energy_kwh'];
    $ts      = !empty($data['timestamp'])
        ? "'" . mysqli_real_escape_string($conex, $data['timestamp']) . "'"
        : 'NOW(3)';

    mysqli_query($conex,
        "INSERT INTO telemetry_pzem
           (codigo_pdu, reading_timestamp, received_at,
            voltage_v, current_a, power_w, power_factor, frequency_hz, energy_kwh, is_buffered)
         VALUES
           ('$cod_sql', $ts, NOW(3),
            $voltage, $current, $power, $pf, $freq, $energy, 0)");

    mysqli_query($conex,
        "UPDATE pdus SET ultimo_contacto = NOW() WHERE codigo_pdu = '$cod_sql'");

    verificar_umbral($conex, $codigo_pdu, 'current_a',    $current);
    verificar_umbral($conex, $codigo_pdu, 'power_w',      $power);
    verificar_umbral($conex, $codigo_pdu, 'frequency_hz', $freq);

    wlog("[PZEM] $codigo_pdu — V=$voltage A=$current W=$power guardado");
}

// ── Procesar telemetría AHT10 ─────────────────────────────────
function procesar_aht10($conex, string $codigo_pdu, array $data): void {
    if (!pdu_premium_activo($conex, $codigo_pdu)) {
        wlog("[AHT10] PDU $codigo_pdu sin premium activo — descartado");
        return;
    }

    $cod_sql = mysqli_real_escape_string($conex, $codigo_pdu);

    if (!isset($data['temperature_c'], $data['humidity_pct'])
        || !is_numeric($data['temperature_c']) || !is_numeric($data['humidity_pct'])) {
        wlog("[AHT10] Campos inválidos — descartado");
        return;
    }

    $temp = (float) $data['temperature_c'];
    $hum  = (float) $data['humidity_pct'];
    $ts   = !empty($data['timestamp'])
        ? "'" . mysqli_real_escape_string($conex, $data['timestamp']) . "'"
        : 'NOW(3)';

    mysqli_query($conex,
        "INSERT INTO telemetry_aht10
           (codigo_pdu, reading_timestamp, received_at, temperature_c, humidity_pct, is_buffered)
         VALUES
           ('$cod_sql', $ts, NOW(3), $temp, $hum, 0)");

    mysqli_query($conex,
        "UPDATE pdus SET ultimo_contacto = NOW() WHERE codigo_pdu = '$cod_sql'");

    verificar_umbral($conex, $codigo_pdu, 'temperature_c', $temp);
    verificar_umbral($conex, $codigo_pdu, 'humidity_pct',  $hum);

    wlog("[AHT10] $codigo_pdu — T=$temp H=$hum guardado");
}

// ── Procesar estado de toma ───────────────────────────────────
function procesar_estado_toma($conex, string $codigo_pdu, int $outlet_number, array $data): void {
    $cod_sql = mysqli_real_escape_string($conex, $codigo_pdu);
    $state   = isset($data['state']) ? (int)(bool) $data['state'] : null;

    if ($state === null) {
        wlog("[ESTADO] $codigo_pdu toma $outlet_number — sin campo state");
        return;
    }

    mysqli_query($conex,
        "UPDATE outlets SET is_on = $state
         WHERE codigo_pdu = '$cod_sql' AND outlet_number = $outlet_number");

    mysqli_query($conex,
        "UPDATE pdus SET ultimo_contacto = NOW() WHERE codigo_pdu = '$cod_sql'");

    wlog("[ESTADO] $codigo_pdu toma $outlet_number → " . ($state ? 'ON' : 'OFF'));
}

// ── Procesar alerta del firmware ──────────────────────────────
function procesar_alerta_firmware($conex, string $codigo_pdu, array $data): void {
    $cod_sql = mysqli_real_escape_string($conex, $codigo_pdu);

    // Validar campos mínimos
    if (!isset($data['tipo'], $data['valor'], $data['umbral'])
        || !is_string($data['tipo'])
        || !is_numeric($data['valor'])
        || !is_numeric($data['umbral'])) {
        wlog("[ALERTA] $codigo_pdu — payload inválido, descartado");
        return;
    }

    $tipo   = mysqli_real_escape_string($conex, $data['tipo']);
    $valor  = (float) $data['valor'];
    $umbral = (float) $data['umbral'];
    $ts     = !empty($data['timestamp'])
        ? "'" . mysqli_real_escape_string($conex, $data['timestamp']) . "'"
        : 'NOW()';

    // Mapeo de tipo firmware → mensaje legible
    $mensajes = [
        'voltaje_bajo'      => "Voltaje por debajo del mínimo permitido",
        'voltaje_alto'      => "Voltaje por encima del máximo permitido",
        'temperatura_alta'  => "Temperatura interna del PDU superó el umbral máximo",
    ];
    $mensaje = isset($mensajes[$data['tipo']])
        ? $mensajes[$data['tipo']]
        : "Alerta del firmware: " . $data['tipo'];
    $mensaje_sql = mysqli_real_escape_string($conex, $mensaje);

    // Insertar en tabla alertas solo si no hay una activa del mismo tipo para este PDU
    $res_check = mysqli_query($conex,
        "SELECT id FROM alertas
         WHERE codigo_pdu = '$cod_sql'
           AND tipo = '$tipo'
           AND estado = 'activa'
         LIMIT 1");

    if (mysqli_num_rows($res_check) > 0) {
        wlog("[ALERTA] $codigo_pdu $tipo ya tiene alerta activa — ignorado");
        return;
    }

    mysqli_query($conex,
        "INSERT INTO alertas
           (codigo_pdu, tipo, mensaje, valor_detectado, umbral_configurado, estado, notificado_mail, created_at)
         VALUES
           ('$cod_sql', '$tipo', '$mensaje_sql', $valor, $umbral, 'activa', 0, NOW())");

    wlog("[ALERTA] $codigo_pdu $tipo — valor=$valor umbral=$umbral guardado");
}

// ── Procesar LWT ──────────────────────────────────────────────
function procesar_lwt($conex, string $codigo_pdu, array $data): void {
    $cod_sql = mysqli_real_escape_string($conex, $codigo_pdu);

    $res = mysqli_query($conex,
        "SELECT id FROM pdus WHERE codigo_pdu = '$cod_sql' LIMIT 1");
    $pdu = mysqli_fetch_assoc($res);
    if (!$pdu) return;

    $device_id = (int) $pdu['id'];
    $msg_sql   = mysqli_real_escape_string($conex, 'Dispositivo desconectado (LWT)');
    $meta_sql  = mysqli_real_escape_string($conex, json_encode([
        'codigo_pdu' => $codigo_pdu,
        'status'     => $data['status'] ?? 'offline',
        'timestamp'  => $data['timestamp'] ?? date('Y-m-d H:i:s'),
    ]));

    mysqli_query($conex,
        "INSERT INTO event_logs
           (device_id, user_id, event_type, severity, message, metadata, event_timestamp)
         VALUES
           ($device_id, NULL, 'network', 'warning', '$msg_sql', '$meta_sql', NOW(3))");

    wlog("[LWT] $codigo_pdu → OFFLINE");
}

// ── Router de mensajes ────────────────────────────────────────
function procesar_mensaje($conex, string $topic, string $message): void {
    $codigo_pdu = extraer_codigo_pdu($topic);
    if (!$codigo_pdu) {
        wlog("[?] Tópico sin codigo_pdu: $topic");
        return;
    }

    $data = json_decode($message, true);
    if (!is_array($data)) {
        wlog("[?] Payload no-JSON en $topic: $message");
        return;
    }

    if (preg_match('#/telemetria/pzem$#', $topic)) {
        procesar_pzem($conex, $codigo_pdu, $data);
    } elseif (preg_match('#/telemetria/aht10$#', $topic)) {
        procesar_aht10($conex, $codigo_pdu, $data);
    } elseif (preg_match('#/estado/toma/(\d+)$#', $topic, $m)) {
        procesar_estado_toma($conex, $codigo_pdu, (int) $m[1], $data);
    } elseif (preg_match('#/alertas$#', $topic)) {
        procesar_alerta_firmware($conex, $codigo_pdu, $data);
    } elseif (preg_match('#/lwt$#', $topic)) {
        procesar_lwt($conex, $codigo_pdu, $data);
    } else {
        wlog("[?] Tópico no manejado: $topic");
    }
}

// ══════════════════════════════════════════════════════════════
//  LOOP PRINCIPAL
// ══════════════════════════════════════════════════════════════

$mqtt_host = $_ENV['MQTT_HOST'] ?? '';
$mqtt_port = (int) ($_ENV['MQTT_PORT'] ?? 8883);
$mqtt_user = $_ENV['MQTT_USER'] ?? '';
$mqtt_pass = $_ENV['MQTT_PASS'] ?? '';

wlog('====== mqtt_worker.php iniciado ======');
wlog("Broker: $mqtt_host:$mqtt_port");

while (true) {
    try {
        $client_id = 'smartrack_worker_' . gethostname();
        $mqtt = new PhpMqttClient($mqtt_host, $mqtt_port, $client_id);

        $settings = (new ConnectionSettings())
            ->setUsername($mqtt_user)
            ->setPassword($mqtt_pass)
            ->setUseTls(true)
            ->setTlsSelfSignedAllowed(false)
            ->setConnectTimeout(10)
            ->setKeepAliveInterval(60);

        $mqtt->connect($settings, true);
        wlog('Conectado al broker MQTT.');

        // Tópicos — se agregó pdu/+/alertas para recibir alertas del firmware
        $topicos = [
            'pdu/+/telemetria/pzem',
            'pdu/+/telemetria/aht10',
            'pdu/+/estado/toma/+',
            'pdu/+/alertas',
            'pdu/+/lwt',
        ];

        foreach ($topicos as $t) {
            $mqtt->subscribe($t, function (string $topic, string $message) use ($conex) {
                global $conex;
                if (!mysqli_ping($conex)) {
                    wlog('DB caída — reconectando...');
                    $conex = db_connect();
                }
                procesar_mensaje($conex, $topic, $message);
            }, 1);
            wlog("Suscrito a: $t");
        }

        $mqtt->loop(true);

    } catch (MqttClientException $e) {
        wlog('ERROR MQTT: ' . $e->getMessage() . ' — reintentando en 5s...');
        sleep(5);
    } catch (Exception $e) {
        wlog('ERROR general: ' . $e->getMessage() . ' — reintentando en 5s...');
        sleep(5);
    }
}
?>