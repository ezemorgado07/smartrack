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
$rol = rol_actual();

// ── Paso 1: PDUs donde user_id = $uid ────────────────────────
// Igual para todos los roles — nunca se muestran PDUs de otros usuarios
$res = mysqli_query($conex,
    "SELECT p.codigo_pdu, p.nombre, p.modo, p.activo, p.ultimo_contacto,
            l.fecha_vencimiento
     FROM pdus p
     LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado = 'activa'
     WHERE p.user_id = $uid AND p.activo = 1
     ORDER BY p.fecha_registro ASC");

$pdus = [];
while ($row = mysqli_fetch_assoc($res)) {
    $online = !empty($row['ultimo_contacto'])
        && (time() - strtotime($row['ultimo_contacto'])) <= 30;

    $premium_vigente = $row['modo'] === 'premium'
        && !empty($row['fecha_vencimiento'])
        && strtotime($row['fecha_vencimiento']) >= strtotime('today');

    $pdus[] = [
        'codigo_pdu'      => $row['codigo_pdu'],
        'nombre'          => $row['nombre'] ?? 'PDU sin nombre',
        'modo'            => $row['modo'],
        'premium_vigente' => $premium_vigente,
        'activo'          => (int) $row['activo'],
        'online'          => $online,
        'ultimo_contacto' => $row['ultimo_contacto'],
    ];
}

// ── Paso 2: fallback por users.codigo_pdu ────────────────────
// Si el usuario no tiene PDUs asignados por user_id, buscar
// el PDU cuyo codigo_pdu coincide con el campo en users.
if (empty($pdus)) {
    $usr_res = mysqli_query($conex,
        "SELECT codigo_pdu FROM users WHERE id = $uid LIMIT 1");
    $usr_row = mysqli_fetch_assoc($usr_res);

    if (!empty($usr_row['codigo_pdu'])) {
        $cod_sql = mysqli_real_escape_string($conex, $usr_row['codigo_pdu']);
        $res_fb  = mysqli_query($conex,
            "SELECT p.codigo_pdu, p.nombre, p.modo, p.activo, p.ultimo_contacto,
                    l.fecha_vencimiento
             FROM pdus p
             LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado = 'activa'
             WHERE p.codigo_pdu = '$cod_sql' AND p.activo = 1
             LIMIT 1");

        $row = mysqli_fetch_assoc($res_fb);
        if ($row) {
            $online = !empty($row['ultimo_contacto'])
                && (time() - strtotime($row['ultimo_contacto'])) <= 30;
            $premium_vigente = $row['modo'] === 'premium'
                && !empty($row['fecha_vencimiento'])
                && strtotime($row['fecha_vencimiento']) >= strtotime('today');

            $pdus[] = [
                'codigo_pdu'      => $row['codigo_pdu'],
                'nombre'          => $row['nombre'] ?? 'PDU sin nombre',
                'modo'            => $row['modo'],
                'premium_vigente' => $premium_vigente,
                'activo'          => (int) $row['activo'],
                'online'          => $online,
                'ultimo_contacto' => $row['ultimo_contacto'],
            ];
        }
    }
}

// ── Paso 3: sin PDUs — devolver array vacío ───────────────────
// No hay fallback al primer PDU del sistema para evitar
// mostrar datos de otros clientes.

mysqli_close($conex);

echo json_encode([
    'success' => true,
    'rol'     => $rol,
    'count'   => count($pdus),
    'pdus'    => $pdus,
]);
?>
