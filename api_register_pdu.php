<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);
// ============================================================
//  SmartRACK — api_register_pdu.php
//  Endpoint de registro de PDU.
//  El ESP32 llama a este endpoint via HTTP POST cuando el
//  usuario presiona el botón físico de registro en el PDU.
//
//  Método:  POST
//  Headers: Content-Type: application/json
//
//  Body JSON esperado:
//  {
//    "mac_address": "AA:BB:CC:DD:EE:FF",
//    "ip_local":    "192.168.1.105",
//    "nombre":      "PDU Rack A"       (opcional)
//  }
//
//  Respuesta exitosa:
//  {
//    "success":    true,
//    "codigo_pdu": "abc123...",
//    "token":      "xyz789...",
//    "modo":       "normal"
//  }
// ============================================================

require_once __DIR__ . '/dbconn.php';

ob_clean();
header('Content-Type: application/json');

// Solo aceptar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit();
}

// Leer body JSON
$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Body JSON inválido o vacío.']);
    exit();
}

// Validar campos obligatorios
if (empty($data['mac_address']) || empty($data['ip_local'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Faltan campos obligatorios: mac_address, ip_local.']);
    exit();
}

// Validar formato MAC (AA:BB:CC:DD:EE:FF)
$mac = strtoupper(trim($data['mac_address']));
if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Formato de MAC address inválido.']);
    exit();
}

// Validar formato IP
$ip = trim($data['ip_local']);
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Formato de IP inválido.']);
    exit();
}

$nombre = isset($data['nombre']) ? mysqli_real_escape_string($conex, trim($data['nombre'])) : null;
$nombre_sql = $nombre ? "'$nombre'" : "NULL";

// Generar codigo_pdu = SHA256(MAC)
// Mismo algoritmo que debe usar el ESP32 para que coincidan
$codigo_pdu = hash('sha256', $mac);

$mac_sql = mysqli_real_escape_string($conex, $mac);
$ip_sql  = mysqli_real_escape_string($conex, $ip);

// Verificar si el PDU ya está registrado
$check = mysqli_query($conex,
    "SELECT id, codigo_pdu, modo, activo FROM pdus WHERE codigo_pdu = '$codigo_pdu' LIMIT 1");
$existing = mysqli_fetch_assoc($check);

if ($existing) {
    // PDU ya registrado: actualizar IP (puede haber cambiado por DHCP) y ultimo_contacto
    mysqli_query($conex,
        "UPDATE pdus
         SET ip_local = '$ip_sql', ultimo_contacto = NOW()
         WHERE codigo_pdu = '$codigo_pdu'");

    // Devolver el token existente
    $tok_res = mysqli_query($conex,
        "SELECT token FROM api_tokens WHERE codigo_pdu = '$codigo_pdu' AND activo = 1 LIMIT 1");
    $tok_row = mysqli_fetch_assoc($tok_res);

    mysqli_close($conex);

    echo json_encode([
        'success'    => true,
        'registered' => false,  // ya existía, solo actualizó IP
        'codigo_pdu' => $codigo_pdu,
        'token'      => $tok_row['token'],
        'modo'       => $existing['modo']
    ]);
    exit();
}

// PDU nuevo: insertar en pdus
$insert_pdu = mysqli_query($conex,
    "INSERT INTO pdus (codigo_pdu, mac_address, ip_local, nombre, modo, activo, ultimo_contacto)
     VALUES ('$codigo_pdu', '$mac_sql', '$ip_sql', $nombre_sql, 'normal', 1, NOW())");

if (!$insert_pdu) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al registrar el PDU.']);
    mysqli_close($conex);
    exit();
}

$pdu_id = mysqli_insert_id($conex);

// Crear las 5 tomas en outlets para este PDU
for ($i = 1; $i <= 5; $i++) {
    mysqli_query($conex,
        "INSERT INTO outlets (device_id, outlet_number, label, is_on, is_locked)
         VALUES ($pdu_id, $i, 'Toma $i', 0, 0)");
}

// Generar token único para la API
$token = hash('sha256', $mac . microtime(true) . random_bytes(16));

mysqli_query($conex,
    "INSERT INTO api_tokens (codigo_pdu, token, activo)
     VALUES ('$codigo_pdu', '$token', 1)");

mysqli_close($conex);

http_response_code(201);
echo json_encode([
    'success'    => true,
    'registered' => true,   // recién registrado
    'codigo_pdu' => $codigo_pdu,
    'token'      => $token,
    'modo'       => 'normal',
    'pdu_id'     => $pdu_id,
    'outlets'    => 5
]);
exit();
?>
