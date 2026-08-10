<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

require_once 'auth.php';
require_once 'dbconn.php';
requerir_login();

ob_clean();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit();
}

// ── Validar token CSRF ────────────────────────────────────────
$csrf_token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
if (!validar_csrf_token($csrf_token)) {
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.']);
    exit();
}

// ── Validar input ─────────────────────────────────────────────
$codigo_raw = isset($_POST['codigo_activacion']) ? trim($_POST['codigo_activacion']) : '';

if (empty($codigo_raw)) {
    echo json_encode(['success' => false, 'error' => 'Debes ingresar el código de activación.']);
    exit();
}

// Formato UUID v4
if (!preg_match('/^[a-f0-9\-]{36}$/i', $codigo_raw)) {
    echo json_encode(['success' => false, 'error' => 'Formato de código inválido.']);
    exit();
}

$cod_sql = mysqli_real_escape_string($conex, strtolower($codigo_raw));

// ── Buscar licencia pendiente con ese código ──────────────────
// No hay validación de tiempo — el código es válido hasta que se use
$res = mysqli_query($conex,
    "SELECT id, codigo_pdu, duracion_años, estado
     FROM licencias
     WHERE codigo_activacion = '$cod_sql'
       AND estado = 'pendiente_activacion'
     LIMIT 1");

$licencia = mysqli_fetch_assoc($res);

if (!$licencia) {
    echo json_encode(['success' => false, 'error' => 'Código inválido o ya utilizado.']);
    mysqli_close($conex);
    exit();
}

$lic_id     = (int) $licencia['id'];
$duracion   = (int) $licencia['duracion_años'];
$codigo_pdu = $licencia['codigo_pdu'];

// ── Si viene codigo_pdu en el POST, verificar que coincida ───
// Permite que el selector de PDU indique a qué dispositivo aplicar el código.
// Si no viene, se usa el codigo_pdu de la licencia (comportamiento estándar).
if (!empty($_POST['codigo_pdu'])) {
    $pdu_post = trim($_POST['codigo_pdu']);
    if ($pdu_post !== $codigo_pdu) {
        echo json_encode(['success' => false, 'error' => 'El código no corresponde al PDU seleccionado.']);
        mysqli_close($conex);
        exit();
    }
}

$pdu_sql = mysqli_real_escape_string($conex, $codigo_pdu);

// ── Activar la licencia ───────────────────────────────────────
$fecha_inicio      = date('Y-m-d');
$fecha_vencimiento = date('Y-m-d', strtotime("+$duracion years"));

$update_lic = mysqli_query($conex,
    "UPDATE licencias
     SET estado            = 'activa',
         fecha_inicio      = '$fecha_inicio',
         fecha_vencimiento = '$fecha_vencimiento',
         codigo_activacion = NULL,
         updated_at        = NOW()
     WHERE id = $lic_id");

if (!$update_lic || mysqli_affected_rows($conex) === 0) {
    echo json_encode(['success' => false, 'error' => 'Error al activar la licencia. Intentá nuevamente.']);
    mysqli_close($conex);
    exit();
}

// Actualizar modo del PDU a premium
mysqli_query($conex,
    "UPDATE pdus SET modo = 'premium' WHERE codigo_pdu = '$pdu_sql'");

// Limpiar fecha_fin_gracia en licencias vencidas del mismo PDU
// (aplica cuando el cliente renueva estando en período de gracia)
mysqli_query($conex,
    "UPDATE licencias
     SET fecha_fin_gracia = NULL
     WHERE codigo_pdu = '$pdu_sql'
       AND estado = 'vencida'
       AND fecha_fin_gracia IS NOT NULL");

// Cancelar otras licencias pendientes del mismo PDU (limpieza)
mysqli_query($conex,
    "UPDATE licencias
     SET estado = 'cancelada'
     WHERE codigo_pdu = '$pdu_sql'
       AND estado = 'pendiente_activacion'
       AND id != $lic_id");

mysqli_close($conex);

echo json_encode([
    'success'           => true,
    'message'           => 'Licencia Premium activada correctamente.',
    'fecha_vencimiento' => $fecha_vencimiento,
    'duracion_años'     => $duracion
]);
?>