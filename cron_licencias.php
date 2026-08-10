<?php
// ============================================================
//  SmartRACK — cron_licencias.php
//  Script de mantenimiento de licencias. Ejecutar diariamente.
//
//  Tareas:
//    A — Detectar licencias vencidas → activar período de gracia
//    B — Detectar gracias vencidas → borrar datos y pasar a normal
//    C — Avisos previos al vencimiento (10 días y 3 días antes)
//
//  Uso manual:
//    php cron_licencias.php
//
//  Cron en alwaysdata (diario a las 02:00):
//    0 2 * * * php /path/to/smartrack/cron_licencias.php
// ============================================================

// Este script corre desde CLI — no necesita sesión PHP
define('SMARTRACK_CRON', true);

require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

// Cargar .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// ── Conexión DB ───────────────────────────────────────────────
$host     = $_ENV['DB_HOST']  ?? 'localhost';
$db_user  = $_ENV['DB_USER']  ?? 'root';
$password = $_ENV['DB_PASS']  ?? '';
$database = $_ENV['DB_NAME']  ?? 'smartrack';
$db_port  = (int) ($_ENV['DB_PORT'] ?? 3306);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conex = mysqli_connect($host, $db_user, $password, $database, $db_port);
    mysqli_set_charset($conex, 'utf8mb4');
} catch (mysqli_sql_exception $e) {
    cron_log('ERROR DB: ' . $e->getMessage());
    exit(1);
}

// ── Logger ────────────────────────────────────────────────────
$log_file = __DIR__ . '/cron_licencias.log';

function cron_log(string $msg): void {
    global $log_file;
    $linea = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($log_file, $linea, FILE_APPEND | LOCK_EX);
    echo $linea;
}

// ── Helper de mail ────────────────────────────────────────────
function enviar_mail(string $destinatario, string $asunto, string $cuerpo_html): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->SMTPAuth   = true;
        $mail->Host       = $_ENV['SMTP_HOST'];
        $mail->Port       = (int) $_ENV['SMTP_PORT'];
        $mail->SMTPSecure = $_ENV['SMTP_SECURE'];
        $mail->Username   = $_ENV['SMTP_USER'];
        $mail->Password   = $_ENV['SMTP_PASS'];
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($_ENV['SMTP_USER'], 'SmartRACK — AucaTek');
        $mail->addAddress($destinatario);
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = $cuerpo_html;
        $mail->send();
        return true;
    } catch (Exception $e) {
        cron_log('Mail ERROR a ' . $destinatario . ': ' . $mail->ErrorInfo);
        return false;
    }
}

function plantilla_mail(string $titulo, string $subtitulo, string $cuerpo, string $color_acento = '#f49825'): string {
    return "
    <div style='font-family:Arial,sans-serif;max-width:520px;margin:0 auto;border:1px solid #e0e0e0;border-radius:10px;overflow:hidden;'>
      <div style='background:#23264f;padding:24px 32px;text-align:center;'>
        <div style='font-family:Montserrat,Arial,sans-serif;font-size:22px;font-weight:700;color:#fff;'>
          Smart<span style='color:#f49825;'>RACK</span>
        </div>
        <div style='font-size:11px;color:rgba(255,255,255,0.5);margin-top:4px;letter-spacing:2px;text-transform:uppercase;'>AucaTek — Innovate IT</div>
      </div>
      <div style='background:#fff;padding:32px;'>
        <p style='font-family:Montserrat,Arial,sans-serif;font-size:15px;color:#23264f;font-weight:700;margin:0 0 6px;'>{$titulo}</p>
        <p style='font-size:13px;color:#888;margin:0 0 20px;'>{$subtitulo}</p>
        {$cuerpo}
      </div>
      <div style='background:#f4f6fb;padding:14px 32px;text-align:center;border-top:1px solid #e8eaf0;'>
        <p style='font-size:11px;color:#bbb;margin:0;'>AucaTek — Av. Corrientes 1386 9 piso, Buenos Aires, CABA</p>
      </div>
    </div>";
}

cron_log('====== Inicio cron_licencias.php ======');

// ══════════════════════════════════════════════════════════════
//  TAREA A — Licencias vencidas → activar período de gracia
// ══════════════════════════════════════════════════════════════
cron_log('[A] Buscando licencias vencidas...');

$res_a = mysqli_query($conex,
    "SELECT l.id, l.codigo_pdu, l.fecha_vencimiento,
            u.email, u.user AS nombre_usuario
     FROM licencias l
     JOIN pdus p ON p.codigo_pdu = l.codigo_pdu
     LEFT JOIN users u ON u.id = p.user_id
     WHERE l.estado = 'activa'
       AND l.fecha_vencimiento < CURDATE()");

$count_a = 0;
while ($lic = mysqli_fetch_assoc($res_a)) {
    $lic_id        = (int) $lic['id'];
    $cod_pdu       = mysqli_real_escape_string($conex, $lic['codigo_pdu']);
    $fecha_gracia  = date('Y-m-d', strtotime($lic['fecha_vencimiento'] . ' +30 days'));

    // Actualizar licencia: vencida + fecha_fin_gracia
    mysqli_query($conex,
        "UPDATE licencias
         SET estado = 'vencida', fecha_fin_gracia = '$fecha_gracia'
         WHERE id = $lic_id");

    // PDU pasa a modo gracia
    mysqli_query($conex,
        "UPDATE pdus SET modo = 'gracia' WHERE codigo_pdu = '$cod_pdu'");

    cron_log("[A] Licencia #$lic_id ({$lic['codigo_pdu']}) → gracia hasta $fecha_gracia");

    // Mail de aviso al cliente
    if (!empty($lic['email'])) {
        $nombre = $lic['nombre_usuario'] ?? 'Cliente';
        $cuerpo = "
        <p style='font-size:14px;color:#444;line-height:1.6;'>Hola <strong>$nombre</strong>,</p>
        <p style='font-size:14px;color:#444;line-height:1.6;'>
          Tu licencia Premium de SmartRACK venció el <strong>{$lic['fecha_vencimiento']}</strong>.
        </p>
        <div style='background:#fff8ee;border-left:4px solid #f49825;border-radius:4px;padding:14px 18px;margin:20px 0;'>
          <div style='font-family:Montserrat,Arial,sans-serif;font-weight:700;color:#23264f;font-size:13px;margin-bottom:6px;'>
            Periodo de gracia activo
          </div>
          <p style='font-size:13px;color:#666;margin:0;line-height:1.6;'>
            Tenes <strong>30 dias</strong> (hasta el <strong>$fecha_gracia</strong>) para renovar tu licencia.
            Durante este tiempo, tu dispositivo sigue registrando datos — y al renovar, recuperas todo el historial acumulado.
          </p>
        </div>
        <p style='font-size:13px;color:#444;line-height:1.6;'>
          Para renovar, ingresa al portal SmartRACK y usa la opcion <strong>Activar Premium</strong> con el codigo que te enviemos.
        </p>
        <p style='font-size:12px;color:#999;margin-top:20px;'>
          Si no renovas antes del $fecha_gracia, los datos del periodo de gracia seran eliminados automaticamente.
        </p>";

        $html = plantilla_mail(
            'Tu licencia Premium vencio',
            'Tenes 30 dias para renovar y recuperar tus datos',
            $cuerpo
        );
        $ok = enviar_mail($lic['email'], 'Tu licencia SmartRACK vencio — Periodo de gracia activo', $html);
        cron_log('[A] Mail ' . ($ok ? 'enviado' : 'FALLIDO') . ' a ' . $lic['email']);
    }
    $count_a++;
}
cron_log("[A] Total procesadas: $count_a");

// ══════════════════════════════════════════════════════════════
//  TAREA B — Períodos de gracia vencidos → borrar datos y normalizar
// ══════════════════════════════════════════════════════════════
cron_log('[B] Buscando gracias vencidas...');

$res_b = mysqli_query($conex,
    "SELECT id, codigo_pdu, fecha_vencimiento, fecha_fin_gracia
     FROM licencias
     WHERE estado = 'vencida'
       AND fecha_fin_gracia IS NOT NULL
       AND fecha_fin_gracia < CURDATE()");

$count_b = 0;
while ($lic = mysqli_fetch_assoc($res_b)) {
    $lic_id          = (int) $lic['id'];
    $cod_pdu         = mysqli_real_escape_string($conex, $lic['codigo_pdu']);
    $fecha_venc      = mysqli_real_escape_string($conex, $lic['fecha_vencimiento']);

    // Borrar solo los datos del período de gracia (desde fecha_vencimiento en adelante)
    $del_pzem = mysqli_query($conex,
        "DELETE FROM telemetry_pzem
         WHERE codigo_pdu = '$cod_pdu'
           AND reading_timestamp >= '$fecha_venc 00:00:00'");
    $rows_pzem = mysqli_affected_rows($conex);

    $del_aht10 = mysqli_query($conex,
        "DELETE FROM telemetry_aht10
         WHERE codigo_pdu = '$cod_pdu'
           AND reading_timestamp >= '$fecha_venc 00:00:00'");
    $rows_aht10 = mysqli_affected_rows($conex);

    // PDU pasa a modo normal
    mysqli_query($conex,
        "UPDATE pdus SET modo = 'normal' WHERE codigo_pdu = '$cod_pdu'");

    // Limpiar fecha_fin_gracia en la licencia
    mysqli_query($conex,
        "UPDATE licencias SET fecha_fin_gracia = NULL WHERE id = $lic_id");

    cron_log("[B] Licencia #$lic_id ({$lic['codigo_pdu']}) → normal. Borrados: $rows_pzem PZEM + $rows_aht10 AHT10");
    $count_b++;
}
cron_log("[B] Total procesadas: $count_b");

// ══════════════════════════════════════════════════════════════
//  TAREA C — Avisos previos al vencimiento (10 y 3 días antes)
// ══════════════════════════════════════════════════════════════
cron_log('[C] Buscando próximos vencimientos...');

// DATEDIFF(fecha_vencimiento, CURDATE()) = 10 o 3 exactamente
// Verificamos que el mail no fue enviado ya para esa cantidad de días
$res_c = mysqli_query($conex,
    "SELECT l.id, l.codigo_pdu, l.fecha_vencimiento,
            DATEDIFF(l.fecha_vencimiento, CURDATE()) AS dias_restantes,
            u.email, u.user AS nombre_usuario
     FROM licencias l
     JOIN pdus p ON p.codigo_pdu = l.codigo_pdu
     LEFT JOIN users u ON u.id = p.user_id
     WHERE l.estado = 'activa'
       AND (
           (DATEDIFF(l.fecha_vencimiento, CURDATE()) = 10 AND l.aviso_10_enviado = 0)
        OR (DATEDIFF(l.fecha_vencimiento, CURDATE()) = 3  AND l.aviso_3_enviado  = 0)
       )");

$count_c = 0;
while ($lic = mysqli_fetch_assoc($res_c)) {
    if (empty($lic['email'])) continue;

    $dias       = (int) $lic['dias_restantes'];
    $nombre     = $lic['nombre_usuario'] ?? 'Cliente';
    $fecha_venc = $lic['fecha_vencimiento'];
    $lic_id     = (int) $lic['id'];

    $urgencia = $dias === 3
        ? '<div style="background:#fef2f2;border-left:4px solid #F87171;border-radius:4px;padding:12px 16px;margin:16px 0;font-size:13px;color:#7f1d1d;"><strong>Quedan solo 3 dias.</strong> Renova ahora para no perder el acceso a la telemetria.</div>'
        : '';

    $cuerpo = "
    <p style='font-size:14px;color:#444;line-height:1.6;'>Hola <strong>$nombre</strong>,</p>
    <p style='font-size:14px;color:#444;line-height:1.6;'>
      Te avisamos que tu licencia Premium de SmartRACK vence en <strong>$dias dias</strong>
      (el <strong>$fecha_venc</strong>).
    </p>
    $urgencia
    <p style='font-size:13px;color:#444;line-height:1.6;'>
      Para renovar, contacta a AucaTek en
      <a href='mailto:info@aucatek.com.ar' style='color:#5f7dbe;'>info@aucatek.com.ar</a>
      y solicita tu nuevo codigo de activacion.
    </p>
    <p style='font-size:13px;color:#666;line-height:1.6;'>
      Si no renova antes del vencimiento, tu dispositivo entrara en un periodo de gracia de 30 dias
      durante el cual seguira registrando datos — que podras recuperar al renovar.
    </p>";

    $html = plantilla_mail(
        "Tu licencia vence en $dias dias",
        "Renovacion de licencia Premium SmartRACK",
        $cuerpo
    );

    $asunto = $dias === 3
        ? 'Aviso urgente: tu licencia SmartRACK vence en 3 dias'
        : 'Recordatorio: tu licencia SmartRACK vence en 10 dias';

    $ok = enviar_mail($lic['email'], $asunto, $html);
    cron_log("[C] Aviso $dias dias — licencia #$lic_id — mail " . ($ok ? 'enviado' : 'FALLIDO') . " a {$lic['email']}");

    if ($ok) {
        $campo = $dias === 3 ? 'aviso_3_enviado' : 'aviso_10_enviado';
        mysqli_query($conex, "UPDATE licencias SET $campo = 1 WHERE id = $lic_id");
    }
    $count_c++;
}
cron_log("[C] Total avisos enviados: $count_c");

mysqli_close($conex);
cron_log('====== Fin cron_licencias.php ======');
exit(0);
?>
