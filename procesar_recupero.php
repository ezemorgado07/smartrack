<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($_POST['email'])) {

    require_once 'dbconn.php';

    $email = mysqli_real_escape_string($conex, $_POST['email']);

    // Buscar en la tabla correcta "users" (no "registronuevo")
    $c = "SELECT * FROM users WHERE email='$email' LIMIT 1";
    $f = mysqli_query($conex, $c);
    $a = mysqli_fetch_assoc($f);

    if (!$a) {
        mysqli_close($conex);
        header("Location: recuperar_clave.php?status=no_registrado");
        exit();
    }

    // Generar token y clave temporal aleatoria
    $token       = md5($email . time() . rand(1000, 9999));
    $clave_nueva = rand(10000000, 99999999);

    // INSERT con manejo de error explícito vía mysqli_error()
    // ON DUPLICATE KEY UPDATE: un mismo email solo tiene un token activo a la vez.
    $c2 = "INSERT INTO recuperar SET email='$email', TOKEN='$token', FECHA_ALTA=NOW(), CLAVE_NUEVA='$clave_nueva'
           ON DUPLICATE KEY UPDATE TOKEN='$token', CLAVE_NUEVA='$clave_nueva', FECHA_ALTA=NOW()";

    $resultado_insert = mysqli_query($conex, $c2);

    if (!$resultado_insert) {
        $db_error = mysqli_error($conex);
        error_log("[SmartRACK] Error INSERT recuperar: " . $db_error);
        mysqli_close($conex);
        header("Location: recuperar_clave.php?status=error_db");
        exit();
    }

    // Construir enlace de confirmación usando la URL base del entorno activo (.env -> APP_URL)
    $app_url = rtrim($_ENV['APP_URL'] ?? 'http://localhost/smartrack', '/');
    $link = $app_url . "/recuperar_clave_confirmar.php?e=" . urlencode($email) . "&t=$token";

    // --- CONFIGURACIÓN Y ENVÍO CON PHPMAILER ---
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->SMTPAuth   = true;
        $mail->Host       = $_ENV['SMTP_HOST'];
        $mail->Port       = $_ENV['SMTP_PORT'];
        $mail->SMTPSecure = $_ENV['SMTP_SECURE'];
        $mail->Username   = $_ENV['SMTP_USER'];
        $mail->Password   = $_ENV['SMTP_PASS'];
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($_ENV['SMTP_USER'], 'SmartRACK — AucaTek');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'SmartRACK — Restablecer tu contraseña';

        // ─────────────────────────────────────────────────────────────────
        // LOGO EN PRODUCCIÓN: una vez que el proyecto esté subido al hosting
        // (alwaysdata), reemplazar la URL de abajo por la URL pública real
        // del logo, por ejemplo:
        // https://tu-dominio.alwaysdata.net/assets/img/LOGO_en_blanco.png
        //
        // Los clientes de correo (Gmail, Outlook, etc.) NO pueden cargar
        // imágenes desde localhost, por eso el logo no se incluye todavía.
        // Descomentar el bloque <tr> del logo más abajo cuando la URL
        // pública esté disponible.
        // ─────────────────────────────────────────────────────────────────
        $logo_url = 'https://TU-DOMINIO-AQUI/assets/img/LOGO_en_blanco.png'; // ← actualizar en producción

        $mail->Body = "
        <table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='background-color:#eef1f6; padding:32px 16px; font-family: Arial, Helvetica, sans-serif;'>
            <tr>
                <td align='center'>
                    <table role='presentation' width='100%' style='max-width:520px;' cellpadding='0' cellspacing='0'>

                        <!-- Header navy -->
                        <tr>
                            <td style='background-color:#23264f; border-radius:10px 10px 0 0; padding:28px 32px; text-align:center;'>
                                <!--
                                <img src='$logo_url' alt='AucaTek' style='width:150px; height:auto; margin-bottom:6px;'>
                                -->
                                <div style='font-family: Arial, Helvetica, sans-serif; font-weight:bold; font-size:20px; color:#ffffff; letter-spacing:-0.3px;'>
                                    SmartRACK
                                </div>
                                <div style='font-family: Arial, Helvetica, sans-serif; font-size:11px; color:rgba(255,255,255,0.55); margin-top:2px;'>
                                    by AucaTek
                                </div>
                            </td>
                        </tr>

                        <!-- Body blanco -->
                        <tr>
                            <td style='background-color:#ffffff; padding:36px 32px; border-left:1px solid #e2e6ee; border-right:1px solid #e2e6ee;'>
                                <h2 style='color:#23264f; font-size:19px; font-family: Arial, Helvetica, sans-serif; margin:0 0 18px;'>
                                    Restablecer contraseña
                                </h2>
                                <p style='color:#3a4256; font-size:14px; line-height:1.6; margin:0 0 16px;'>Hola,</p>
                                <p style='color:#3a4256; font-size:14px; line-height:1.6; margin:0 0 20px;'>
                                    Recibimos una solicitud para recuperar el acceso a tu cuenta en SmartRACK.
                                    Generamos una contraseña temporal para vos:
                                </p>

                                <table role='presentation' width='100%' cellpadding='0' cellspacing='0'>
                                    <tr>
                                        <td align='center' style='padding:6px 0 24px;'>
                                            <span style='display:inline-block; background-color:#fdf3e7; color:#b06800; font-size:20px; font-weight:bold; font-family: Arial, Helvetica, sans-serif; padding:14px 28px; border:1px solid #f49825; border-radius:6px; letter-spacing:2px;'>
                                                $clave_nueva
                                            </span>
                                        </td>
                                    </tr>
                                </table>

                                <p style='color:#3a4256; font-size:14px; line-height:1.6; margin:0 0 22px;'>
                                    Para activar esta contraseña temporal, confirmá el cambio haciendo clic en el siguiente botón:
                                </p>

                                <table role='presentation' width='100%' cellpadding='0' cellspacing='0'>
                                    <tr>
                                        <td align='center' style='padding-bottom:24px;'>
                                            <a href='$link' style='background-color:#f49825; color:#ffffff; font-family: Arial, Helvetica, sans-serif; font-size:14px; font-weight:bold; text-decoration:none; padding:13px 30px; border-radius:6px; display:inline-block;'>
                                                Confirmar Cambio de Clave
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                                <p style='font-size:11px; color:#8993a8; font-family: Arial, Helvetica, sans-serif; margin:0 0 6px;'>
                                    Si el botón no funciona, copiá y pegá este enlace en tu navegador:
                                </p>
                                <p style='font-size:11px; color:#5f7dbe; font-family: Arial, Helvetica, sans-serif; word-break:break-all; background:#f5f7fb; padding:10px 12px; border-radius:4px; margin:0;'>
                                    $link
                                </p>
                            </td>
                        </tr>

                        <!-- Footer -->
                        <tr>
                            <td style='background-color:#f5f7fb; border-radius:0 0 10px 10px; border:1px solid #e2e6ee; border-top:none; padding:18px 32px; text-align:center;'>
                                <p style='font-size:11px; color:#9aa3b8; font-family: Arial, Helvetica, sans-serif; margin:0;'>
                                    Si no solicitaste este cambio, podés ignorar este correo de forma segura.
                                </p>
                            </td>
                        </tr>

                    </table>
                </td>
            </tr>
        </table>
        ";

        $mail->send();
        mysqli_close($conex);

        header("Location: recuperar_clave.php?status=enviado&email=" . urlencode($email));
        exit();

    } catch (Exception $e) {
        error_log("[SmartRACK] Error PHPMailer: " . $mail->ErrorInfo);
        mysqli_close($conex);
        header("Location: recuperar_clave.php?status=error_envio");
        exit();
    }

} else {
    header("Location: recuperar_clave.php?status=acceso_invalido");
    exit();
}
?>