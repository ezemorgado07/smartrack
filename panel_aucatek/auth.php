<?php
// ============================================================
//  Panel AucaTek — Autenticación independiente
//  Sesión separada del portal SmartRACK.
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_name('AUCATEK_PANEL');
    // Cookie de sesión endurecida: HttpOnly, Secure, SameSite=Strict.
    // Debe ir ANTES de session_start().
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ── Timeout de inactividad (30 min) ──────────────────────────
// Si el usuario lleva más de 30 minutos sin actividad, se destruye
// la sesión automáticamente y se lo redirige al login.
define('PANEL_SESSION_TIMEOUT', 1800); // 30 minutos en segundos

function panel_verificar_inactividad() {
    if (!empty($_SESSION['panel_logueado']) && $_SESSION['panel_logueado'] === true) {
        $ahora = time();
        $ultima = $_SESSION['panel_ultima_actividad'] ?? 0;
        if ($ultima > 0 && ($ahora - $ultima) > PANEL_SESSION_TIMEOUT) {
            // Sesión expirada por inactividad
            panel_logout();
            header('Location: index.php?timeout=1');
            exit();
        }
        // Actualizar timestamp de última actividad
        $_SESSION['panel_ultima_actividad'] = $ahora;
    }
}

// ── Headers de seguridad ──────────────────────────────────────
function panel_headers_seguridad() {
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}

// ── CSRF ──────────────────────────────────────────────────────
function panel_csrf_token() {
    if (empty($_SESSION['panel_csrf'])) {
        $_SESSION['panel_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['panel_csrf'];
}

function panel_validar_csrf($token) {
    return !empty($token) && !empty($_SESSION['panel_csrf'])
        && hash_equals($_SESSION['panel_csrf'], $token);
}

// ── Requiere login: si no hay sesión, redirige al login ──────
function panel_requerir_login() {
    panel_verificar_inactividad();
    if (empty($_SESSION['panel_logueado']) || $_SESSION['panel_logueado'] !== true) {
        header('Location: index.php');
        exit();
    }
}

// ── Cerrar sesión ─────────────────────────────────────────────
function panel_logout() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']);
    }
    session_destroy();
}

// ── Rate limiting de login ────────────────────────────────────
// Ventana: 30 minutos. Máx intentos antes de bloqueo: 3.
// Al 2do intento fallido: mail de alerta a Diego.
// Al 3er intento: bloqueo de IP por 30 min (sin revelar el motivo).

define('PANEL_RL_VENTANA',    1800); // segundos hasta resetear conteo
define('PANEL_RL_MAX',           3); // intentos antes de bloquear
define('PANEL_RL_MAIL_EN',       2); // intentos antes de mandar mail

function panel_get_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Verifica si la IP está bloqueada.
 * Retorna true si está bloqueada (no debe dejarse pasar).
 */
function panel_ip_bloqueada($conex): bool {
    $ip  = mysqli_real_escape_string($conex, panel_get_ip());
    $ven = PANEL_RL_VENTANA;
    $res = mysqli_query($conex,
        "SELECT COUNT(*) AS intentos
         FROM panel_login_attempts
         WHERE ip = '$ip'
           AND created_at >= DATE_SUB(NOW(), INTERVAL $ven SECOND)");
    $row = mysqli_fetch_assoc($res);
    return (int)($row['intentos'] ?? 0) >= PANEL_RL_MAX;
}

/**
 * Registra un intento fallido y devuelve el total de intentos
 * en la ventana actual para esa IP.
 */
function panel_registrar_intento_fallido($conex, string $user_intentado): int {
    $ip      = mysqli_real_escape_string($conex, panel_get_ip());
    $user_sq = mysqli_real_escape_string($conex, $user_intentado);
    $ua_sq   = mysqli_real_escape_string($conex,
                   substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255));

    mysqli_query($conex,
        "INSERT INTO panel_login_attempts (ip, user_intentado, user_agent, created_at)
         VALUES ('$ip', '$user_sq', '$ua_sq', NOW())");

    $ven = PANEL_RL_VENTANA;
    $res = mysqli_query($conex,
        "SELECT COUNT(*) AS intentos
         FROM panel_login_attempts
         WHERE ip = '$ip'
           AND created_at >= DATE_SUB(NOW(), INTERVAL $ven SECOND)");
    $row = mysqli_fetch_assoc($res);
    return (int)($row['intentos'] ?? 1);
}

/**
 * Envía mail de alerta a Diego cuando se detecta el 2do intento fallido.
 */
function panel_enviar_alerta_mail(string $ip, string $user_intentado, int $intentos): void {
    // Cargamos PHPMailer desde el vendor del portal principal (un nivel arriba)
    $vendor = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($vendor)) return;
    require_once $vendor;

    // Cargar .env si no está cargado
    if (empty($_ENV['SMTP_HOST'])) {
        if (class_exists('Dotenv\\Dotenv')) {
            $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
            $dotenv->safeLoad();
        }
    }

    $mail_host = $_ENV['SMTP_HOST']        ?? '';
    $mail_user = $_ENV['SMTP_USER']        ?? '';
    $mail_pass = $_ENV['SMTP_PASS']        ?? '';
    $mail_port = (int)($_ENV['SMTP_PORT']  ?? 587);
    $mail_from = $_ENV['SMTP_USER']        ?? '';
    $mail_dest = $_ENV['PANEL_ALERT_MAIL'] ?? $mail_user;

    if (empty($mail_host) || empty($mail_dest)) return;

    try {
        $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host       = $mail_host;
        $mailer->SMTPAuth   = true;
        $mailer->Username   = $mail_user;
        $mailer->Password   = $mail_pass;
        $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Port       = $mail_port;
        $mailer->CharSet    = 'UTF-8';

        $mailer->setFrom($mail_from, 'SmartRACK — Panel AucaTek');
        $mailer->addAddress($mail_dest);

        $fecha  = date('d/m/Y H:i:s');
        $mailer->isHTML(true);
        $mailer->Subject = "⚠️ Alerta: intento de acceso al Panel AucaTek — $fecha";
        $mailer->Body    = "
<table width='100%' cellpadding='0' cellspacing='0' style='font-family:Arial,sans-serif;'>
  <tr><td style='background:#23264f;padding:20px 30px;'>
    <span style='color:#f49825;font-size:20px;font-weight:700;'>AucaTek</span>
    <span style='color:#fff;font-size:14px;margin-left:8px;'>Panel — Alerta de seguridad</span>
  </td></tr>
  <tr><td style='padding:30px;background:#f9f9f9;'>
    <p style='font-size:15px;color:#1f2433;margin:0 0 16px;'>
      Se detectaron <strong>$intentos intentos fallidos</strong> de acceso al Panel de Administración.
    </p>
    <table style='width:100%;border-collapse:collapse;font-size:14px;'>
      <tr style='background:#e8eaf0;'>
        <td style='padding:8px 12px;font-weight:600;color:#23264f;width:140px;'>Fecha y hora</td>
        <td style='padding:8px 12px;color:#333;'>$fecha (hora del servidor)</td>
      </tr>
      <tr>
        <td style='padding:8px 12px;font-weight:600;color:#23264f;'>IP de origen</td>
        <td style='padding:8px 12px;color:#333;'>$ip</td>
      </tr>
      <tr style='background:#e8eaf0;'>
        <td style='padding:8px 12px;font-weight:600;color:#23264f;'>Usuario intentado</td>
        <td style='padding:8px 12px;color:#333;'>$user_intentado</td>
      </tr>
      <tr>
        <td style='padding:8px 12px;font-weight:600;color:#23264f;'>Intentos en ventana</td>
        <td style='padding:8px 12px;color:#c0392b;font-weight:700;'>$intentos / " . PANEL_RL_MAX . "</td>
      </tr>
    </table>
    <p style='font-size:13px;color:#6b7280;margin:20px 0 0;'>
      Si a los " . PANEL_RL_MAX . " intentos la IP sigue siendo bloqueada automáticamente por 30 minutos.<br>
      Si no fuiste vos, no hagas nada: el sistema ya está bloqueando esa IP.<br>
      Si crees que es un ataque, contactá al equipo SmartRACK.
    </p>
  </td></tr>
  <tr><td style='background:#23264f;padding:12px 30px;text-align:center;'>
    <span style='color:rgba(255,255,255,0.4);font-size:11px;'>SmartRACK ePDU — Panel AucaTek &mdash; Mensaje automático</span>
  </td></tr>
</table>";

        $mailer->send();
    } catch (\Throwable $e) {
        // No romper el flujo si el mail falla
        error_log('panel_alert_mail error: ' . $e->getMessage());
    }
}
