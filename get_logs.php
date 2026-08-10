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

// ── Resolver codigo_pdu ───────────────────────────────────────
$codigo_pdu_param = isset($_GET['codigo_pdu']) ? trim($_GET['codigo_pdu']) : '';

if (!empty($codigo_pdu_param)) {
    $param_sql = mysqli_real_escape_string($conex, $codigo_pdu_param);

    // Validar que le pertenezca al usuario o sea admin
    if (rol_actual() === 'admin') {
        $pdu_res = mysqli_query($conex,
            "SELECT id FROM pdus WHERE codigo_pdu = '$param_sql' AND activo = 1 LIMIT 1");
    } else {
        $pdu_res = mysqli_query($conex,
            "SELECT id FROM pdus WHERE codigo_pdu = '$param_sql' AND activo = 1 AND user_id = $uid LIMIT 1");
    }

    $pdu = mysqli_fetch_assoc($pdu_res);

    if (!$pdu) {
        echo json_encode(['success' => false, 'error' => 'PDU no encontrado o no autorizado.']);
        mysqli_close($conex);
        exit();
    }

    $device_id = (int) $pdu['id'];

} else {
    // Sin parámetro — resolver igual que get_telemetry.php
    $usr_res = mysqli_query($conex, "SELECT codigo_pdu FROM users WHERE id = $uid LIMIT 1");
    $usr_row = mysqli_fetch_assoc($usr_res);

    if (!empty($usr_row['codigo_pdu'])) {
        $cod_tmp = mysqli_real_escape_string($conex, $usr_row['codigo_pdu']);
        $pdu_res = mysqli_query($conex,
            "SELECT id FROM pdus WHERE codigo_pdu = '$cod_tmp' AND activo = 1 LIMIT 1");
    } else {
        $pdu_res = mysqli_query($conex,
            "SELECT id FROM pdus WHERE activo = 1 ORDER BY id ASC LIMIT 1");
    }

    $pdu = mysqli_fetch_assoc($pdu_res);

    if (!$pdu) {
        echo json_encode(['success' => true, 'logs' => []]);
        mysqli_close($conex);
        exit();
    }

    $device_id = (int) $pdu['id'];
}

// ── Obtener últimos 10 logs ───────────────────────────────────
$res = mysqli_query($conex,
    "SELECT event_timestamp, event_type, severity, message
     FROM event_logs
     WHERE device_id = $device_id
     ORDER BY event_timestamp DESC
     LIMIT 10");

$logs = [];
while ($row = mysqli_fetch_assoc($res)) {
    $logs[] = [
        'event_timestamp' => $row['event_timestamp'],
        'event_type'      => $row['event_type'],
        'severity'        => $row['severity'],
        'message'         => $row['message'],
    ];
}

mysqli_close($conex);

echo json_encode([
    'success' => true,
    'count'   => count($logs),
    'logs'    => $logs,
]);
?>
