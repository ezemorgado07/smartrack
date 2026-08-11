<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);
// ============================================================
//  SmartRACK — api_commands.php
//  El ESP32 consulta este endpoint periódicamente (cada 2-5s)
//  para ver si hay comandos pendientes (on/off/reboot por toma).
//  También recibe la confirmación de ejecución.
//
//  -- CONSULTAR comandos pendientes --
//  Método:  GET
//  Headers: X-PDU-Token: {token}
//  Query:   ?codigo_pdu=abc123
//
//  Respuesta:
//  {
//    "success": true,
//    "commands": [
//      { "id": 5, "outlet_number": 3, "command": "off" }
//    ]
//  }
//
//  -- CONFIRMAR ejecución de un comando --
//  Método:  POST
//  Headers: Content-Type: application/json
//           X-PDU-Token: {token}
//  Body:
//  {
//    "codigo_pdu": "abc123...",
//    "command_id": 5,
//    "status":     "executed"   // o "failed"
//  }
// ============================================================

require_once __DIR__ . '/dbconn.php';

ob_clean();
header('Content-Type: application/json');

// ── Autenticación por token ──────────────────────────────────
$token = isset($_SERVER['HTTP_X_PDU_TOKEN'])
    ? trim($_SERVER['HTTP_X_PDU_TOKEN'])
    : '';

if (empty($token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token requerido.']);
    exit();
}

$token_sql = mysqli_real_escape_string($conex, $token);
$tok_res   = mysqli_query($conex,
    "SELECT codigo_pdu FROM api_tokens WHERE token = '$token_sql' AND activo = 1 LIMIT 1");
$tok_row   = mysqli_fetch_assoc($tok_res);

if (!$tok_row) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token inválido.']);
    exit();
}

$codigo_pdu = $tok_row['codigo_pdu'];
$cod_sql    = mysqli_real_escape_string($conex, $codigo_pdu);

// ── GET: consultar comandos pendientes ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // Verificar que el codigo_pdu del query string coincide con el token
    $qp = (isset($_GET['codigo_pdu']) && is_string($_GET['codigo_pdu'])) ? trim($_GET['codigo_pdu']) : '';
    if ($qp !== $codigo_pdu) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'codigo_pdu no coincide con el token.']);
        exit();
    }

    // Actualizar ultimo_contacto
    mysqli_query($conex,
        "UPDATE pdus SET ultimo_contacto = NOW() WHERE codigo_pdu = '$cod_sql'");

    // Traer comandos pendientes ordenados por antigüedad
    $res = mysqli_query($conex,
        "SELECT id, outlet_number, command
         FROM outlet_commands
         WHERE codigo_pdu = '$cod_sql' AND status = 'pending'
         ORDER BY issued_at ASC
         LIMIT 10");

    $commands = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $commands[] = [
            'id'            => (int) $row['id'],
            'outlet_number' => (int) $row['outlet_number'],
            'command'       => $row['command']
        ];
    }

    mysqli_close($conex);
    echo json_encode(['success' => true, 'commands' => $commands]);
    exit();
}

// ── POST: confirmar ejecución ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    if (!$data || empty($data['command_id']) || empty($data['status'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Faltan campos: command_id, status.']);
        exit();
    }

    // Verificar que el codigo_pdu del body coincide con el token
    if (empty($data['codigo_pdu']) || $data['codigo_pdu'] !== $codigo_pdu) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'codigo_pdu no coincide con el token.']);
        exit();
    }

    $command_id = (int) $data['command_id'];
    $status     = in_array($data['status'], ['executed', 'failed']) ? $data['status'] : 'failed';

    // Verificar que el comando pertenece a este PDU
    $cmd_res = mysqli_query($conex,
        "SELECT id FROM outlet_commands
         WHERE id = $command_id AND codigo_pdu = '$cod_sql' AND status = 'pending'
         LIMIT 1");

    if (!mysqli_fetch_assoc($cmd_res)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Comando no encontrado o ya procesado.']);
        exit();
    }

    // Marcar como ejecutado/fallido
    mysqli_query($conex,
        "UPDATE outlet_commands
         SET status = '$status', executed_at = NOW(3)
         WHERE id = $command_id");

    // Si se ejecutó correctamente, sincronizar estado en outlets
    if ($status === 'executed') {
        $cmd_detail = mysqli_query($conex,
            "SELECT outlet_number, command FROM outlet_commands WHERE id = $command_id LIMIT 1");
        $cmd = mysqli_fetch_assoc($cmd_detail);

        if ($cmd && $cmd['command'] !== 'reboot') {
            $new_state = $cmd['command'] === 'on' ? 1 : 0;
            $outlet_n  = (int) $cmd['outlet_number'];

            // Obtener device_id del PDU
            $pdu_id_res = mysqli_query($conex,
                "SELECT id FROM pdus WHERE codigo_pdu = '$cod_sql' LIMIT 1");
            $pdu_id_row = mysqli_fetch_assoc($pdu_id_res);
            $device_id  = (int) $pdu_id_row['id'];

            mysqli_query($conex,
                "UPDATE outlets SET is_on = $new_state
                 WHERE device_id = $device_id AND outlet_number = $outlet_n");
        }
    }

    mysqli_close($conex);
    echo json_encode(['success' => true, 'command_id' => $command_id, 'status' => $status]);
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
mysqli_close($conex);
exit();
?>