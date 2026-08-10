<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);
// ============================================================
//  SmartRACK — api_telemetry.php
//  Recibe lecturas del sensor PZEM-004T desde el ESP32.
//  Solo acepta PDUs con modo 'premium' y licencia vigente.
//
//  Método:  POST
//  Headers:
//    Content-Type: application/json
//    Authorization: Bearer {token}
//
//  Body JSON:
//  {
//    "codigo_pdu":      "abc123...",
//    "reading_timestamp": "2026-07-05T14:30:00",  (opcional)
//    "voltage_v":       220.5,
//    "current_a":       1.250,
//    "power_w":         275.50,
//    "power_factor":    0.912,
//    "frequency_hz":    50.01,
//    "energy_kwh":      10.250,
//    "is_buffered":     0
//  }
// ============================================================

require_once __DIR__ . '/dbconn.php';

ob_clean();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit();
}

// ── Autenticación: Authorization: Bearer TOKEN ────────────────
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token requerido (Authorization: Bearer TOKEN).']);
    exit();
}

$token     = trim($matches[1]);
$token_sql = mysqli_real_escape_string($conex, $token);

$tok_res = mysqli_query($conex,
    "SELECT codigo_pdu FROM api_tokens
     WHERE token = '$token_sql' AND activo = 1 LIMIT 1");
$tok_row = mysqli_fetch_assoc($tok_res);

if (!$tok_row) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token inválido o inactivo.']);
    exit();
}

$token_codigo_pdu = $tok_row['codigo_pdu'];

// ── Leer body JSON ────────────────────────────────────────────
$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Body JSON inválido o vacío.']);
    exit();
}

// codigo_pdu del body debe coincidir con el del token
if (empty($data['codigo_pdu']) || $data['codigo_pdu'] !== $token_codigo_pdu) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'codigo_pdu no coincide con el token.']);
    exit();
}

$codigo_pdu = $data['codigo_pdu'];
$cod_sql    = mysqli_real_escape_string($conex, $codigo_pdu);

// ── Verificar PDU: existe, activo, modo premium, licencia ─────
$pdu_res = mysqli_query($conex,
    "SELECT p.modo, p.activo,
            l.fecha_vencimiento
     FROM pdus p
     LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu
                           AND l.estado = 'activa'
     WHERE p.codigo_pdu = '$cod_sql'
     LIMIT 1");
$pdu = mysqli_fetch_assoc($pdu_res);

if (!$pdu) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'PDU no registrado.']);
    exit();
}

if (!$pdu['activo']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'PDU inactivo.']);
    exit();
}

if ($pdu['modo'] === 'normal') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Telemetría no disponible en modo normal.',
        'modo'    => 'normal'
    ]);
    exit();
}

if (empty($pdu['fecha_vencimiento'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'PDU premium sin licencia activa.']);
    exit();
}

if (strtotime($pdu['fecha_vencimiento']) < strtotime('today')) {
    mysqli_query($conex,
        "UPDATE licencias SET estado = 'vencida'
         WHERE codigo_pdu = '$cod_sql' AND estado = 'activa'");
    mysqli_query($conex,
        "UPDATE pdus SET modo = 'normal' WHERE codigo_pdu = '$cod_sql'");
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Licencia premium vencida. PDU degradado a modo normal.']);
    exit();
}

// ── Validar campos de telemetría ──────────────────────────────
$required = ['voltage_v', 'current_a', 'power_w', 'power_factor', 'frequency_hz', 'energy_kwh'];
foreach ($required as $field) {
    if (!isset($data[$field]) || !is_numeric($data[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Campo requerido o inválido: $field"]);
        exit();
    }
}

$voltage_v    = round((float) $data['voltage_v'],    2);
$current_a    = round((float) $data['current_a'],    3);
$power_w      = round((float) $data['power_w'],      2);
$power_factor = round((float) $data['power_factor'], 3);
$frequency_hz = round((float) $data['frequency_hz'], 2);
$energy_kwh   = round((float) $data['energy_kwh'],   3);
$is_buffered  = isset($data['is_buffered']) ? (int)(bool)$data['is_buffered'] : 0;

// reading_timestamp: usa el del ESP32 si viene, sino NOW(3)
if (!empty($data['reading_timestamp'])) {
    $ts = mysqli_real_escape_string($conex, $data['reading_timestamp']);
    $reading_ts = "'$ts'";
} else {
    $reading_ts = "NOW(3)";
}

// ── Insertar en telemetry_pzem (codigo_pdu, no device_id) ────
mysqli_query($conex,
    "INSERT INTO telemetry_pzem
       (codigo_pdu, reading_timestamp, received_at,
        voltage_v, current_a, power_w,
        power_factor, frequency_hz, energy_kwh, is_buffered)
     VALUES
       ('$cod_sql', $reading_ts, NOW(3),
        $voltage_v, $current_a, $power_w,
        $power_factor, $frequency_hz, $energy_kwh, $is_buffered)");

$insert_id = mysqli_insert_id($conex);

// Actualizar ultimo_contacto del PDU
mysqli_query($conex,
    "UPDATE pdus SET ultimo_contacto = NOW() WHERE codigo_pdu = '$cod_sql'");

mysqli_close($conex);

echo json_encode([
    'success'     => true,
    'id'          => $insert_id,
    'is_buffered' => $is_buffered
]);
exit();
?>
