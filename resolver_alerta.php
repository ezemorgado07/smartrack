<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

require_once 'auth.php';
require_once 'dbconn.php';
requerir_rol(array('admin', 'operator'));

ob_clean();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit();
}

$alerta_id = isset($_POST['alerta_id']) ? (int) $_POST['alerta_id'] : 0;

if ($alerta_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID de alerta inválido.']);
    exit();
}

$uid = (int) $_SESSION['usuario_id'];

// Verificar que la alerta pertenece a un PDU del usuario
// Admin puede resolver cualquier alerta; operator solo las de su PDU
$rol = rol_actual();

if ($rol === 'admin') {
    $alerta_res = mysqli_query($conex,
        "SELECT id, codigo_pdu, estado FROM alertas WHERE id = $alerta_id LIMIT 1");
} else {
    // Operator: solo alertas de PDUs vinculados a él
    $alerta_res = mysqli_query($conex,
        "SELECT a.id, a.codigo_pdu, a.estado
         FROM alertas a
         JOIN pdus p ON p.codigo_pdu = a.codigo_pdu
         WHERE a.id = $alerta_id
           AND (p.user_id = $uid OR p.codigo_pdu = (
               SELECT codigo_pdu FROM users WHERE id = $uid LIMIT 1
           ))
         LIMIT 1");
}

$alerta = mysqli_fetch_assoc($alerta_res);

if (!$alerta) {
    echo json_encode(['success' => false, 'error' => 'Alerta no encontrada o sin permiso.']);
    mysqli_close($conex);
    exit();
}

if ($alerta['estado'] === 'resuelta') {
    echo json_encode(['success' => false, 'error' => 'La alerta ya fue resuelta.']);
    mysqli_close($conex);
    exit();
}

// Marcar como resuelta
$update = mysqli_query($conex,
    "UPDATE alertas
     SET estado = 'resuelta', resolved_at = NOW(3)
     WHERE id = $alerta_id");

if (!$update || mysqli_affected_rows($conex) === 0) {
    echo json_encode(['success' => false, 'error' => 'Error al resolver la alerta.']);
    mysqli_close($conex);
    exit();
}

mysqli_close($conex);

echo json_encode([
    'success'   => true,
    'alerta_id' => $alerta_id,
    'message'   => 'Alerta marcada como resuelta.'
]);
?>
