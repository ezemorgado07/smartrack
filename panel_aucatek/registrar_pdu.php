<?php
require_once 'auth.php';
require_once 'dbconn.php';

panel_headers_seguridad();
panel_requerir_login();

header('Content-Type: application/json; charset=utf-8');

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

// ── CSRF ──────────────────────────────────────────────────────
$csrf = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (!panel_validar_csrf($csrf)) {
    echo json_encode(['error' => 'Token de seguridad inválido. Recargá la página.']);
    exit;
}

// ── Parámetros ────────────────────────────────────────────────
$mac    = isset($_POST['mac'])    ? trim($_POST['mac'])    : '';
$nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
$ip     = isset($_POST['ip'])     ? trim($_POST['ip'])     : '';

// ── Validar MAC ───────────────────────────────────────────────
if (!preg_match('/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/', $mac)) {
    echo json_encode(['error' => 'Dirección MAC inválida. Formato: AA:BB:CC:DD:EE:FF']);
    exit;
}

if ($nombre === '') {
    echo json_encode(['error' => 'El nombre del dispositivo es obligatorio.']);
    exit;
}
if (strlen($nombre) > 100) {
    echo json_encode(['error' => 'El nombre no puede superar los 100 caracteres.']);
    exit;
}

// ── Validar IP (opcional) ─────────────────────────────────────
if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
    echo json_encode(['error' => 'La IP local ingresada no es válida.']);
    exit;
}

// ── Generar código PDU desde MAC ─────────────────────────────
$mac_upper  = strtoupper($mac);
$codigo_pdu = str_replace(':', '-', $mac_upper);

// ── Escape ────────────────────────────────────────────────────
$codigo_pdu_esc = mysqli_real_escape_string($conex, $codigo_pdu);
$mac_esc        = mysqli_real_escape_string($conex, $mac_upper);
$nombre_esc     = mysqli_real_escape_string($conex, $nombre);
$ip_esc         = ($ip !== '') ? mysqli_real_escape_string($conex, $ip) : null;

// ── Verificar duplicado ───────────────────────────────────────
$res_dup = mysqli_query($conex,
    "SELECT id FROM pdus WHERE codigo_pdu = '$codigo_pdu_esc' LIMIT 1");
if (mysqli_num_rows($res_dup) > 0) {
    echo json_encode(['error' => 'Ya existe un PDU registrado con esa MAC / código PDU.']);
    exit;
}

// ── INSERT ────────────────────────────────────────────────────
$ip_sql = ($ip_esc !== null) ? "'$ip_esc'" : 'NULL';

$ok = mysqli_query($conex,
    "INSERT INTO pdus (codigo_pdu, mac_address, ip_local, nombre, modo, activo, fecha_registro)
     VALUES ('$codigo_pdu_esc', '$mac_esc', $ip_sql, '$nombre_esc', 'normal', 1, NOW())");

if (!$ok) {
    error_log('registrar_pdu.php DB error: ' . mysqli_error($conex));
    echo json_encode(['error' => 'Error al registrar en la base de datos. Intentá nuevamente.']);
    exit;
}

echo json_encode([
    'success'    => true,
    'codigo_pdu' => $codigo_pdu
]);
