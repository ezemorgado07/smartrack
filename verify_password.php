<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);
require_once 'auth.php';
require_once 'dbconn.php';
ob_clean();
header('Content-Type: application/json');

// Solo admin autenticado puede usar este endpoint
if (!isset($_SESSION['usuario']) || $_SESSION['rol'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['password'])) {
    echo json_encode(['success' => false, 'error' => 'Solicitud inválida']);
    exit();
}

$usuario = $_SESSION['usuario'];
$sql     = "SELECT pass FROM users WHERE user = '" . mysqli_real_escape_string($conex, $usuario) . "' AND is_active = 1 LIMIT 1";
$result  = mysqli_query($conex, $sql);
mysqli_close($conex);

if (mysqli_num_rows($result) === 1) {
    $row = mysqli_fetch_assoc($result);
    if (password_verify($_POST['password'], $row['pass'])) {
        echo json_encode(['success' => true]);
        exit();
    }
}

echo json_encode(['success' => false, 'error' => 'Contraseña incorrecta']);
exit();
?>