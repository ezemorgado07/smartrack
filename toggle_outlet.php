<?php
// Captura cualquier output espurio (warnings, notices, BOM) antes del JSON.
// Si PHP emite algo antes de nuestro json_encode, ob_clean() lo descarta.
ob_start();

error_reporting(0);
ini_set('display_errors', 0);

require_once 'auth.php';
require_once 'dbconn.php';
require_once 'MqttClient.php';
requerir_rol(array('admin', 'operator'));

// Descartar cualquier output que haya escapado hasta acá (session warnings, etc.)
ob_clean();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array('success' => false, 'error' => 'Método no permitido.'));
    exit();
}

// ── Validar token CSRF ────────────────────────────────────────
$csrf_token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (!validar_csrf_token($csrf_token)) {
    echo json_encode(array('success' => false, 'error' => 'Token CSRF inválido.'));
    exit();
}

$outlet_number = isset($_POST['outlet_number']) ? (int) $_POST['outlet_number'] : 0;
$new_state     = isset($_POST['new_state'])     ? (int) $_POST['new_state']     : -1;

// Validar rango de toma
if ($outlet_number < 1 || $outlet_number > 5) {
    echo json_encode(array('success' => false, 'error' => 'Número de toma inválido.'));
    exit();
}

// Validar estado (0 = off, 1 = on)
if ($new_state !== 0 && $new_state !== 1) {
    echo json_encode(array('success' => false, 'error' => 'Estado inválido.'));
    exit();
}

// ── Obtener PDU del usuario ───────────────────────────────────
// Acepta codigo_pdu opcional en POST para soporte multi-PDU.
// Si viene, valida que le pertenezca al usuario.
// Si no viene, usa el PDU por defecto del usuario.
$uid = isset($_SESSION['usuario_id']) ? (int) $_SESSION['usuario_id'] : 0;

if ($uid === 0) {
    echo json_encode(array('success' => false, 'error' => 'Sesión inválida.'));
    exit();
}

$codigo_pdu_param = isset($_POST['codigo_pdu']) ? trim($_POST['codigo_pdu']) : '';

if (!empty($codigo_pdu_param)) {
    // PDU explícito — validar que le pertenezca al usuario
    $param_sql = mysqli_real_escape_string($conex, $codigo_pdu_param);
    $pdu_res   = mysqli_query($conex,
        "SELECT id, codigo_pdu FROM pdus
         WHERE codigo_pdu = '$param_sql'
           AND activo = 1
           AND user_id = $uid
         LIMIT 1");
    $pdu = mysqli_fetch_assoc($pdu_res);

    if (!$pdu) {
        echo json_encode(array('success' => false, 'error' => 'PDU no encontrado o no autorizado.'));
        mysqli_close($conex);
        exit();
    }

    $device_id  = (int) $pdu['id'];
    $codigo_pdu = $pdu['codigo_pdu'];
    $cod_sql    = $param_sql;

} else {
    // Sin parámetro — resolver por users.codigo_pdu, luego user_id, luego fallback
    $usr_res = mysqli_query($conex,
        "SELECT codigo_pdu FROM users WHERE id = $uid LIMIT 1");
    $usr_row = mysqli_fetch_assoc($usr_res);

    $codigo_pdu_usuario = isset($usr_row['codigo_pdu']) ? $usr_row['codigo_pdu'] : '';

    if (!empty($codigo_pdu_usuario)) {
        $cod_tmp = mysqli_real_escape_string($conex, $codigo_pdu_usuario);
        $pdu_res = mysqli_query($conex,
            "SELECT id, codigo_pdu FROM pdus
             WHERE codigo_pdu = '$cod_tmp' AND activo = 1 LIMIT 1");
    } else {
        $pdu_res = mysqli_query($conex,
            "SELECT id, codigo_pdu FROM pdus
             WHERE activo = 1 ORDER BY id ASC LIMIT 1");
    }

    $pdu = mysqli_fetch_assoc($pdu_res);

    if (!$pdu) {
        echo json_encode(array('success' => false, 'error' => 'No hay un PDU activo vinculado a este usuario.'));
        exit();
    }

    // Siempre asignar desde $pdu resuelto, independientemente del camino tomado
    $device_id  = (int) $pdu['id'];
    $codigo_pdu = $pdu['codigo_pdu'];
    $cod_sql    = mysqli_real_escape_string($conex, $codigo_pdu);
}

// ── Verificar que la toma existe y no está bloqueada ─────────
$res = mysqli_query($conex,
    "SELECT id, is_locked, is_on
     FROM outlets
     WHERE outlet_number = $outlet_number AND codigo_pdu = '$cod_sql'
     LIMIT 1");

if (!$res || mysqli_num_rows($res) === 0) {
    echo json_encode(array('success' => false, 'error' => 'Toma no encontrada.'));
    exit();
}

$outlet = mysqli_fetch_assoc($res);

if ($outlet['is_locked']) {
    echo json_encode(array('success' => false, 'error' => 'La toma está bloqueada y no puede ser controlada.'));
    exit();
}

// Sin cambio: ya está en el estado pedido
if ((int) $outlet['is_on'] === $new_state) {
    mysqli_close($conex);
    echo json_encode(array(
        'success' => true,
        'outlet'  => $outlet_number,
        'is_on'   => $new_state,
        'note'    => 'La toma ya estaba en ese estado.'
    ));
    exit();
}

// ── Actualizar estado en outlets usando codigo_pdu ────────────
$update = mysqli_query($conex,
    "UPDATE outlets
     SET is_on = $new_state
     WHERE outlet_number = $outlet_number AND codigo_pdu = '$cod_sql'");

if (!$update || mysqli_affected_rows($conex) === 0) {
    echo json_encode(array('success' => false, 'error' => 'Error al actualizar la toma en la base de datos.'));
    exit();
}

// ── Publicar comando al ESP32 vía MQTT ────────────────────────
// El UPDATE de arriba es un reflejo optimista para el dashboard.
// El comando real viaja por MQTT; el ESP32 acciona el relé y luego
// confirma el estado real publicando en pdu/{codigo_pdu}/estado/toma/{N},
// que el worker procesa para corregir outlets.is_on si difiere.
$comando_topic   = "pdu/{$codigo_pdu}/comando/toma/{$outlet_number}";
$comando_payload = json_encode(array('command' => $new_state === 1 ? 'on' : 'off'));

$mqtt_ok = false;
try {
    $mqtt = new MqttClient();
    $mqtt_ok = $mqtt->publish($comando_topic, $comando_payload, 1);
    $mqtt->disconnect();
} catch (\Throwable $e) {
    // No romper el flujo: el dashboard ya refleja el cambio de forma optimista.
    // Si el broker está caído, el comando no llega pero el UPDATE ya se hizo.
    error_log('toggle_outlet MQTT error: ' . $e->getMessage());
    $mqtt_ok = false;
}

// ── Registrar en event_logs ───────────────────────────────────
// device_id se mantiene como legacy mientras event_logs no migra.
// Verificamos el INSERT explícitamente para no perder el log silenciosamente.
$usuario  = mysqli_real_escape_string($conex, $_SESSION['usuario']);
$accion   = $new_state === 1 ? 'encendida' : 'apagada';
$message  = "Toma $outlet_number $accion por $usuario";
$meta_sql = mysqli_real_escape_string($conex, json_encode(array(
    'outlet'         => $outlet_number,
    'new_state'      => $new_state,
    'codigo_pdu'     => $codigo_pdu,
    'user'           => $_SESSION['usuario'],
    'transport'      => 'mqtt',
    'mqtt_published' => $mqtt_ok
)));

$log_ok = mysqli_query($conex,
    "INSERT INTO event_logs
       (device_id, user_id, event_type, severity, message, metadata, event_timestamp)
     VALUES
       ($device_id, $uid, 'control', 'info', '$message', '$meta_sql', NOW(3))");

mysqli_close($conex);

// Responder siempre con el resultado del UPDATE.
// Si el log falló lo indicamos en la respuesta pero no es un error crítico.
echo json_encode(array(
    'success'         => true,
    'outlet'          => $outlet_number,
    'is_on'           => $new_state,
    'log_saved'       => $log_ok ? true : false,
    'mqtt_published'  => $mqtt_ok ? true : false
));
?>