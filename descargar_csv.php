<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

require_once 'auth.php';
require_once 'dbconn.php';
requerir_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    exit('Método no permitido.');
}

$uid = (int) $_SESSION['usuario_id'];

// ── Validar inputs ────────────────────────────────────────────
$tipo  = (isset($_POST['tipo'])  && is_string($_POST['tipo']))  ? trim($_POST['tipo'])  : '';
$desde = (isset($_POST['desde']) && is_string($_POST['desde'])) ? trim($_POST['desde']) : '';
$hasta = (isset($_POST['hasta']) && is_string($_POST['hasta'])) ? trim($_POST['hasta']) : '';

if (!in_array($tipo, ['pzem', 'aht10', 'ambos'])) {
    ob_clean(); exit('Tipo inválido.');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
    ob_clean(); exit('Formato de fecha inválido.');
}

$ts_desde = strtotime($desde);
$ts_hasta = strtotime($hasta);

if ($ts_desde === false || $ts_hasta === false) {
    ob_clean(); exit('Fecha inválida.');
}

if ($ts_desde > $ts_hasta) {
    ob_clean(); exit('La fecha "desde" no puede ser mayor a "hasta".');
}

$diff_dias = (int) ceil(($ts_hasta - $ts_desde) / 86400);
if ($diff_dias > 90) {
    ob_clean(); exit('El rango no puede superar los 90 días.');
}

// ── Resolver PDU del usuario (tres niveles) ───────────────────
// 1. users.codigo_pdu
// 2. pdus.user_id = $uid
// 3. Primer PDU activo (fallback dev)
$usr_res = mysqli_query($conex, "SELECT codigo_pdu FROM users WHERE id = $uid LIMIT 1");
$usr_row = mysqli_fetch_assoc($usr_res);
$cpdu_usuario = $usr_row['codigo_pdu'] ?? '';

if (!empty($cpdu_usuario)) {
    $cpdu_sql = mysqli_real_escape_string($conex, $cpdu_usuario);
    $pdu_res  = mysqli_query($conex,
        "SELECT p.id, p.codigo_pdu, p.modo, p.nombre, l.fecha_vencimiento
         FROM pdus p
         LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado = 'activa'
         WHERE p.codigo_pdu = '$cpdu_sql' AND p.activo = 1 LIMIT 1");
} else {
    $pdu_res = mysqli_query($conex,
        "SELECT p.id, p.codigo_pdu, p.modo, p.nombre, l.fecha_vencimiento
         FROM pdus p
         LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado = 'activa'
         WHERE p.user_id = $uid AND p.activo = 1 ORDER BY p.id ASC LIMIT 1");
}

$pdu = mysqli_fetch_assoc($pdu_res);

// Fallback dev: primer PDU activo del sistema
if (!$pdu) {
    $pdu_res = mysqli_query($conex,
        "SELECT p.id, p.codigo_pdu, p.modo, p.nombre, l.fecha_vencimiento
         FROM pdus p
         LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado = 'activa'
         WHERE p.activo = 1 ORDER BY p.id ASC LIMIT 1");
    $pdu = mysqli_fetch_assoc($pdu_res);
}

if (!$pdu) {
    ob_clean(); exit('PDU no encontrado o no autorizado.');
}

// Si vino codigo_pdu por POST, validar que coincida con el PDU resuelto
$codigo_pdu_param = (isset($_POST['codigo_pdu']) && is_string($_POST['codigo_pdu'])) ? trim($_POST['codigo_pdu']) : '';
if (!empty($codigo_pdu_param) && $codigo_pdu_param !== $pdu['codigo_pdu']) {
    ob_clean(); exit('PDU no encontrado o no autorizado.');
}

// Verificar Premium vigente
$modo = $pdu['modo'];
$lic_vigente = ($modo === 'premium' && !empty($pdu['fecha_vencimiento']))
    ? strtotime($pdu['fecha_vencimiento']) >= strtotime('today')
    : false;

if (!$lic_vigente) {
    ob_clean(); exit('Esta función requiere una licencia Premium activa.');
}

$codigo_pdu = $pdu['codigo_pdu'];
$cod_sql    = mysqli_real_escape_string($conex, $codigo_pdu);
$desde_sql  = mysqli_real_escape_string($conex, $desde . ' 00:00:00');
$hasta_sql  = mysqli_real_escape_string($conex, $hasta . ' 23:59:59');

// ── Generar CSV ───────────────────────────────────────────────
$cod_short = substr($codigo_pdu, 0, 12);
$filename  = 'smartrack_' . $cod_short . '_' . $tipo . '_' . $desde . '_' . $hasta . '.csv';

ob_clean();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$out = fopen('php://output', 'w');
// BOM UTF-8 para compatibilidad con Excel
fwrite($out, "\xEF\xBB\xBF");

if ($tipo === 'pzem') {
    // ── Solo PZEM ─────────────────────────────────────────────
    fputcsv($out, ['Timestamp', 'Voltaje (V)', 'Corriente (A)', 'Potencia (W)',
                   'Factor Potencia', 'Frecuencia (Hz)', 'Energia (kWh)', 'Buffer']);

    $res = mysqli_query($conex,
        "SELECT reading_timestamp, voltage_v, current_a, power_w,
                power_factor, frequency_hz, energy_kwh, is_buffered
         FROM telemetry_pzem
         WHERE codigo_pdu = '$cod_sql'
           AND reading_timestamp BETWEEN '$desde_sql' AND '$hasta_sql'
         ORDER BY reading_timestamp ASC");

    $rows = 0;
    while ($row = mysqli_fetch_assoc($res)) {
        fputcsv($out, [
            $row['reading_timestamp'],
            $row['voltage_v'],
            $row['current_a'],
            $row['power_w'],
            $row['power_factor'],
            $row['frequency_hz'],
            $row['energy_kwh'],
            $row['is_buffered'],
        ]);
        $rows++;
    }

} elseif ($tipo === 'aht10') {
    // ── Solo AHT10 ────────────────────────────────────────────
    fputcsv($out, ['Timestamp', 'Temperatura (C)', 'Humedad (%)', 'Buffer']);

    $res = mysqli_query($conex,
        "SELECT reading_timestamp, temperature_c, humidity_pct, is_buffered
         FROM telemetry_aht10
         WHERE codigo_pdu = '$cod_sql'
           AND reading_timestamp BETWEEN '$desde_sql' AND '$hasta_sql'
         ORDER BY reading_timestamp ASC");

    $rows = 0;
    while ($row = mysqli_fetch_assoc($res)) {
        fputcsv($out, [
            $row['reading_timestamp'],
            $row['temperature_c'],
            $row['humidity_pct'],
            $row['is_buffered'],
        ]);
        $rows++;
    }

} else {
    // ── Ambos: LEFT JOIN por minuto ───────────────────────────
    // Agrupa PZEM y AHT10 por minuto (DATE_FORMAT trunca a HH:MM:00)
    // Si no hay AHT10 para un minuto con PZEM, las columnas AHT10 quedan vacías
    fputcsv($out, ['Timestamp', 'Voltaje (V)', 'Corriente (A)', 'Potencia (W)',
                   'Factor Potencia', 'Frecuencia (Hz)', 'Energia (kWh)',
                   'Temperatura (C)', 'Humedad (%)', 'Buffer']);

    $res = mysqli_query($conex,
        "SELECT
             p.reading_timestamp,
             p.voltage_v, p.current_a, p.power_w,
             p.power_factor, p.frequency_hz, p.energy_kwh,
             a.temperature_c, a.humidity_pct,
             p.is_buffered
         FROM telemetry_pzem p
         LEFT JOIN telemetry_aht10 a
             ON  a.codigo_pdu = p.codigo_pdu
             AND DATE_FORMAT(a.reading_timestamp, '%Y-%m-%d %H:%i') =
                 DATE_FORMAT(p.reading_timestamp, '%Y-%m-%d %H:%i')
         WHERE p.codigo_pdu = '$cod_sql'
           AND p.reading_timestamp BETWEEN '$desde_sql' AND '$hasta_sql'
         ORDER BY p.reading_timestamp ASC");

    $rows = 0;
    while ($row = mysqli_fetch_assoc($res)) {
        fputcsv($out, [
            $row['reading_timestamp'],
            $row['voltage_v'],
            $row['current_a'],
            $row['power_w'],
            $row['power_factor'],
            $row['frequency_hz'],
            $row['energy_kwh'],
            $row['temperature_c'] ?? '',
            $row['humidity_pct']  ?? '',
            $row['is_buffered'],
        ]);
        $rows++;
    }
}

fclose($out);
mysqli_close($conex);
exit();
?>