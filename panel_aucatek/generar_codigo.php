<?php
// ============================================================
//  Panel AucaTek — Generar código Premium y enviarlo por mail
// ============================================================
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

require_once 'auth.php';
require_once 'dbconn.php';
panel_requerir_login();

require_once __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

ob_clean();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit();
}

if (!panel_validar_csrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.']);
    exit();
}

$codigo_pdu = isset($_POST['codigo_pdu']) ? trim($_POST['codigo_pdu']) : '';
$duracion   = (int) ($_POST['duracion'] ?? 1);

if (empty($codigo_pdu) || !in_array($duracion, [1, 3, 5])) {
    echo json_encode(['success' => false, 'error' => 'Datos inválidos.']);
    exit();
}

$pdu_sql = mysqli_real_escape_string($conex, $codigo_pdu);

// ── Verificar que el PDU existe ───────────────────────────────
$res_pdu = mysqli_query($conex,
    "SELECT p.id, p.codigo_pdu, u.email, u.nombre, u.user
     FROM pdus p
     LEFT JOIN users u ON u.codigo_pdu = p.codigo_pdu
     WHERE p.codigo_pdu = '$pdu_sql' LIMIT 1");
$pdu = mysqli_fetch_assoc($res_pdu);

if (!$pdu) {
    echo json_encode(['success' => false, 'error' => 'El PDU no existe.']);
    exit();
}

if (empty($pdu['email'])) {
    echo json_encode(['success' => false, 'error' => 'El PDU no tiene un usuario con email vinculado. El cliente debe registrarse primero.']);
    exit();
}

// ── Generar código UUID v4 ────────────────────────────────────
function uuid_v4() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

$codigo_activacion = uuid_v4();
$cod_sql = mysqli_real_escape_string($conex, $codigo_activacion);

// ── Cancelar licencias pendientes anteriores del mismo PDU ────
mysqli_query($conex,
    "UPDATE licencias SET estado = 'cancelada'
     WHERE codigo_pdu = '$pdu_sql' AND estado = 'pendiente_activacion'");

// ── Crear la licencia pendiente ───────────────────────────────
$insert = mysqli_query($conex,
    "INSERT INTO licencias (codigo_pdu, codigo_activacion, duracion_años, estado, created_at)
     VALUES ('$pdu_sql', '$cod_sql', $duracion, 'pendiente_activacion', NOW())");

if (!$insert) {
    echo json_encode(['success' => false, 'error' => 'Error al crear la licencia.']);
    exit();
}

// ── Enviar el código por mail ─────────────────────────────────
$mail_enviado = false;
try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $_ENV['MAIL_HOST']     ?? '';
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['MAIL_USERNAME'] ?? '';
    $mail->Password   = $_ENV['MAIL_PASSWORD'] ?? '';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int) ($_ENV['MAIL_PORT'] ?? 587);
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom($_ENV['MAIL_FROM'] ?? $_ENV['MAIL_USERNAME'] ?? 'info@aucatek.com.ar', 'AucaTek — SmartRACK');
    $mail->addAddress($pdu['email'], $pdu['nombre'] ?? '');

    $mail->isHTML(true);
    $mail->Subject = 'Tu código de activación Premium — SmartRACK';
    $mail->Body = "
    <div style='font-family:Arial,sans-serif;max-width:520px;margin:0 auto;'>
        <div style='background:#23264f;padding:24px;text-align:center;border-radius:10px 10px 0 0;'>
            <h1 style='color:#fff;margin:0;font-size:22px;'>Smart<span style='color:#f49825;'>RACK</span></h1>
        </div>
        <div style='background:#f8f9fb;padding:32px;border-radius:0 0 10px 10px;'>
            <p style='color:#1f2937;font-size:15px;'>Hola " . htmlspecialchars($pdu['nombre'] ?? $pdu['user']) . ",</p>
            <p style='color:#1f2937;font-size:15px;line-height:1.6;'>
                AucaTek generó tu código de activación Premium para tu dispositivo SmartRACK.
                Ingresá este código en el portal, en la sección <strong>Activar Premium</strong>:
            </p>
            <div style='background:#23264f;color:#f49825;font-family:monospace;font-size:18px;
                        padding:16px;border-radius:8px;text-align:center;letter-spacing:1px;margin:24px 0;'>
                " . htmlspecialchars($codigo_activacion) . "
            </div>
            <p style='color:#6b7280;font-size:13px;line-height:1.6;'>
                Duración de la licencia: <strong>" . $duracion . " año" . ($duracion > 1 ? 's' : '') . "</strong><br>
                Dispositivo: <strong>" . htmlspecialchars($pdu['codigo_pdu']) . "</strong>
            </p>
            <p style='color:#6b7280;font-size:13px;'>
                Una vez activado, vas a poder ver la telemetría en tiempo real, gráficos históricos,
                alertas automáticas y exportación de datos.
            </p>
            <hr style='border:none;border-top:1px solid #e2e4ed;margin:24px 0;'>
            <p style='color:#9ca3af;font-size:12px;text-align:center;'>
                AucaTek — innovate IT · info@aucatek.com.ar
            </p>
        </div>
    </div>";

    $mail->send();
    $mail_enviado = true;
} catch (Exception $e) {
    $mail_enviado = false;
}

mysqli_close($conex);

echo json_encode([
    'success'      => true,
    'codigo'       => $codigo_activacion,
    'mail_enviado' => $mail_enviado,
    'email'        => $pdu['email'],
    'message'      => $mail_enviado
        ? 'Código generado y enviado por mail a ' . $pdu['email']
        : 'Código generado, pero falló el envío del mail. Copialo y envialo manualmente.'
]);
?>
