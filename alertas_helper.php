<?php
// ============================================================
//  SmartRACK — alertas_helper.php
//  Funciones compartidas para verificar umbrales y generar alertas.
//  Include desde api_telemetry.php y api_telemetry_aht10.php.
//  No requiere sesión PHP — se usa desde endpoints del ESP32.
// ============================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Verifica una métrica contra sus umbrales y genera alerta si corresponde.
 *
 * @param mysqli $conex       Conexión DB abierta
 * @param string $codigo_pdu  codigo_pdu del dispositivo
 * @param string $metrica     Nombre del campo (ej: 'current_a', 'temperature_c')
 * @param float  $valor       Valor medido
 */
function verificar_umbral(
    mysqli $conex,
    string $codigo_pdu,
    string $metrica,
    float  $valor
): void {
    $cod_sql     = mysqli_real_escape_string($conex, $codigo_pdu);
    $metrica_sql = mysqli_real_escape_string($conex, $metrica);

    // Obtener configuración: primero específica del PDU, luego global
    // ORDER BY: NULL (global) al final, específico primero
    $cfg_res = mysqli_query($conex,
        "SELECT umbral_min, umbral_max
         FROM configuracion_alertas
         WHERE metrica = '$metrica_sql'
           AND activo = 1
           AND (codigo_pdu = '$cod_sql' OR codigo_pdu IS NULL)
         ORDER BY (codigo_pdu IS NULL) ASC
         LIMIT 1");

    $cfg = mysqli_fetch_assoc($cfg_res);
    if (!$cfg) return; // Sin umbral configurado para esta métrica

    $umbral_min = $cfg['umbral_min'] !== null ? (float) $cfg['umbral_min'] : null;
    $umbral_max = $cfg['umbral_max'] !== null ? (float) $cfg['umbral_max'] : null;

    // ¿Supera algún umbral?
    $superado = false;
    $umbral_ref = null;
    $tipo_violacion = '';

    if ($umbral_max !== null && $valor > $umbral_max) {
        $superado = true;
        $umbral_ref = $umbral_max;
        $tipo_violacion = 'maximo';
    } elseif ($umbral_min !== null && $valor < $umbral_min) {
        $superado = true;
        $umbral_ref = $umbral_min;
        $tipo_violacion = 'minimo';
    }

    if (!$superado) return;

    // Verificar si ya hay alerta activa del mismo tipo para este PDU
    $existe_res = mysqli_query($conex,
        "SELECT id FROM alertas
         WHERE codigo_pdu = '$cod_sql'
           AND tipo = '$metrica_sql'
           AND estado = 'activa'
         LIMIT 1");

    if (mysqli_fetch_assoc($existe_res)) return; // Ya existe, no duplicar

    // Construir mensaje descriptivo
    $labels = [
        'temperature_c' => 'Temperatura del rack',
        'humidity_pct'  => 'Humedad del rack',
        'current_a'     => 'Corriente',
        'power_w'       => 'Potencia',
        'frequency_hz'  => 'Frecuencia de red',
        'voltage_v'     => 'Voltaje',
    ];
    $label = $labels[$metrica] ?? $metrica;
    $mensaje = $tipo_violacion === 'maximo'
        ? "$label supero el umbral maximo configurado ($umbral_ref)"
        : "$label cayo por debajo del umbral minimo configurado ($umbral_ref)";

    $valor_sql   = (float) $valor;
    $umbral_sql  = (float) $umbral_ref;
    $mensaje_sql = mysqli_real_escape_string($conex, $mensaje);

    // Insertar alerta
    mysqli_query($conex,
        "INSERT INTO alertas
           (codigo_pdu, tipo, valor_detectado, umbral_configurado,
            estado, notificado_mail, created_at)
         VALUES
           ('$cod_sql', '$metrica_sql', $valor_sql, $umbral_sql,
            'activa', 0, NOW(3))");

    $alerta_id = mysqli_insert_id($conex);

    // Insertar notificación para el usuario propietario del PDU
    $usr_res = mysqli_query($conex,
        "SELECT u.id, u.email, u.user
         FROM pdus p
         LEFT JOIN users u ON u.id = p.user_id
         WHERE p.codigo_pdu = '$cod_sql'
         LIMIT 1");
    $usr = mysqli_fetch_assoc($usr_res);

    if ($usr && $usr['id']) {
        $user_id   = (int) $usr['id'];
        $titulo    = mysqli_real_escape_string($conex, "Alerta: $label");
        $notif_msg = mysqli_real_escape_string($conex, $mensaje);
        mysqli_query($conex,
            "INSERT INTO notificaciones
               (user_id, alerta_id, codigo_pdu, titulo, mensaje, tipo, leida, created_at)
             VALUES
               ($user_id, $alerta_id, '$cod_sql', '$titulo', '$notif_msg', 'danger', 0, NOW(3))");
    }

    // Mandar mail — solo si hay email y modo es premium vigente
    if (!empty($usr['email'])) {
        // Verificar que el PDU está en modo premium con licencia vigente
        $modo_res = mysqli_query($conex,
            "SELECT p.modo, l.fecha_vencimiento
             FROM pdus p
             LEFT JOIN licencias l ON l.codigo_pdu = p.codigo_pdu AND l.estado = 'activa'
             WHERE p.codigo_pdu = '$cod_sql' LIMIT 1");
        $modo_row = mysqli_fetch_assoc($modo_res);

        $es_premium = $modo_row
            && $modo_row['modo'] === 'premium'
            && !empty($modo_row['fecha_vencimiento'])
            && strtotime($modo_row['fecha_vencimiento']) >= strtotime('today');

        if ($es_premium) {
            enviar_mail_alerta($usr['email'], $usr['user'], $label, $valor, $umbral_ref, $tipo_violacion, $codigo_pdu);

            // Marcar como notificado
            mysqli_query($conex,
                "UPDATE alertas SET notificado_mail = 1 WHERE id = $alerta_id");
        }
    }
}

/**
 * Envía mail de alerta al propietario del PDU.
 */
function enviar_mail_alerta(
    string $email,
    string $nombre,
    string $label,
    float  $valor,
    float  $umbral,
    string $tipo_violacion,
    string $codigo_pdu
): void {
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->SMTPAuth   = true;
        $mail->Host       = $_ENV['SMTP_HOST']   ?? '';
        $mail->Port       = (int) ($_ENV['SMTP_PORT'] ?? 587);
        $mail->SMTPSecure = $_ENV['SMTP_SECURE'] ?? 'tls';
        $mail->Username   = $_ENV['SMTP_USER']   ?? '';
        $mail->Password   = $_ENV['SMTP_PASS']   ?? '';
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($_ENV['SMTP_USER'] ?? '', 'SmartRACK — AucaTek');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = "Alerta SmartRACK: $label fuera de rango";

        $color_alerta = '#F87171';
        $cod_short    = substr($codigo_pdu, 0, 12) . '...';
        $tipo_txt     = $tipo_violacion === 'maximo' ? 'supero el maximo' : 'cayo por debajo del minimo';

        $mail->Body = "
        <div style='font-family:Arial,sans-serif;max-width:520px;margin:0 auto;border:1px solid #e0e0e0;border-radius:10px;overflow:hidden;'>
          <div style='background:#23264f;padding:24px 32px;text-align:center;'>
            <div style='font-family:Montserrat,Arial,sans-serif;font-size:22px;font-weight:700;color:#fff;'>
              Smart<span style='color:#f49825;'>RACK</span>
            </div>
            <div style='font-size:11px;color:rgba(255,255,255,0.5);margin-top:4px;letter-spacing:2px;text-transform:uppercase;'>AucaTek — Innovate IT</div>
          </div>
          <div style='background:#fff;padding:32px;'>
            <div style='background:#fef2f2;border-left:4px solid $color_alerta;border-radius:6px;padding:14px 18px;margin-bottom:20px;'>
              <div style='font-family:Montserrat,Arial,sans-serif;font-weight:700;font-size:14px;color:#7f1d1d;margin-bottom:4px;'>
                Alerta detectada en tu PDU
              </div>
              <div style='font-size:13px;color:#7f1d1d;'>$label $tipo_txt configurado.</div>
            </div>
            <p style='font-size:14px;color:#444;line-height:1.6;'>Hola <strong>$nombre</strong>,</p>
            <p style='font-size:14px;color:#444;line-height:1.6;'>
              Se detecto un valor fuera de rango en tu dispositivo SmartRACK (<code>$cod_short</code>).
            </p>
            <table style='width:100%;border-collapse:collapse;margin:20px 0;font-size:13px;'>
              <tr style='background:#f4f6fb;'>
                <td style='padding:10px 14px;font-weight:700;color:#23264f;border-bottom:1px solid #e8eaf0;'>Metrica</td>
                <td style='padding:10px 14px;border-bottom:1px solid #e8eaf0;'>$label</td>
              </tr>
              <tr>
                <td style='padding:10px 14px;font-weight:700;color:#23264f;border-bottom:1px solid #e8eaf0;'>Valor detectado</td>
                <td style='padding:10px 14px;border-bottom:1px solid #e8eaf0;color:$color_alerta;font-weight:700;'>$valor</td>
              </tr>
              <tr style='background:#f4f6fb;'>
                <td style='padding:10px 14px;font-weight:700;color:#23264f;'>Umbral configurado</td>
                <td style='padding:10px 14px;'>$umbral</td>
              </tr>
            </table>
            <p style='font-size:13px;color:#666;line-height:1.6;'>
              Ingresa al portal SmartRACK para ver el detalle y resolver la alerta.
            </p>
          </div>
          <div style='background:#f4f6fb;padding:14px 32px;text-align:center;border-top:1px solid #e8eaf0;'>
            <p style='font-size:11px;color:#bbb;margin:0;'>AucaTek — Av. Corrientes 1386 9 piso, Buenos Aires, CABA</p>
          </div>
        </div>";

        $mail->send();
    } catch (Exception $e) {
        // Silencioso — el mail de alerta no puede romper el flujo del ESP32
    }
}
?>
