<?php
require_once 'auth.php';
require_once 'dbconn.php';
panel_headers_seguridad();

// Si ya está logueado, ir al dashboard
if (!empty($_SESSION['panel_logueado']) && $_SESSION['panel_logueado'] === true) {
    header('Location: dashboard.php');
    exit();
}

$error   = '';
$bloqueado = false;

// Mensaje de sesión expirada por inactividad
$timeout_msg = '';
if (!empty($_GET['timeout'])) {
    $timeout_msg = 'Tu sesión expiró por inactividad. Ingresá nuevamente.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!panel_validar_csrf($csrf)) {
        $error = 'La sesión del formulario expiró. Recargá la página.';
    } else {

        // ── Verificar bloqueo ANTES de procesar credenciales ─────
        // Si la IP ya tiene 3+ intentos en la ventana, rechazar sin dar pistas.
        if (panel_ip_bloqueada($conex)) {
            $bloqueado = true;
            $error = 'Acceso temporalmente no disponible. Intentá en unos minutos.';
        } else {

            $user = isset($_POST['user']) ? trim($_POST['user']) : '';
            $pass = $_POST['pass'] ?? '';

            if (empty($user) || empty($pass)) {
                $error = 'Completá usuario y contraseña.';
            } else {
                $user_sql = mysqli_real_escape_string($conex, $user);
                $res = mysqli_query($conex,
                    "SELECT id, user, pass, nombre FROM users
                     WHERE user = '$user_sql' AND rol = 'superadmin' LIMIT 1");
                $row = mysqli_fetch_assoc($res);

                if ($row && password_verify($pass, $row['pass'])) {
                    // Login correcto: sesión válida, registrar actividad inicial
                    session_regenerate_id(true);
                    $_SESSION['panel_logueado']          = true;
                    $_SESSION['panel_user']              = $row['user'];
                    $_SESSION['panel_nombre']            = $row['nombre'];
                    $_SESSION['panel_uid']               = (int) $row['id'];
                    $_SESSION['panel_ultima_actividad']  = time();
                    header('Location: dashboard.php');
                    exit();
                } else {
                    // ── Registrar intento fallido ─────────────────
                    $intentos = panel_registrar_intento_fallido($conex, $user);

                    // Al 2do intento: mandar mail de alerta a Diego
                    if ($intentos === PANEL_RL_MAIL_EN) {
                        panel_enviar_alerta_mail(panel_get_ip(), $user, $intentos);
                    }

                    // Al 3er intento: IP queda bloqueada en la próxima verificación.
                    // El mensaje es el mismo siempre (no revelar cuántos intentos quedan).
                    $error = 'Usuario o contraseña incorrectos.';
                }
            }
        } // fin else (no bloqueado)
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel AucaTek — Acceso</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:        #23264f;
            --navy-deep:   #1a1d3a;
            --orange:      #f49825;
            --orange-hover:#e08820;
            --celeste:     #5f7dbe;

            --panel-bg:    #ffffff;
            --field-bg:    #f5f7fb;
            --field-border:#d5dae8;
            --text:        #1f2433;
            --text-muted:  #6b7280;
            --text-invert: #ffffff;

            --error-bg:    #fdecec;
            --error-border:#dc2626;
            --error-text:  #a01818;

            --warn-bg:     #fff8ec;
            --warn-border: #f49825;
            --warn-text:   #7a4a00;

            --sp-1: 6px;  --sp-2: 12px; --sp-3: 18px;
            --sp-4: 24px; --sp-5: 36px;

            --radius:   10px;
            --radius-sm: 7px;
            --shadow:   0 18px 50px rgba(15, 18, 45, 0.35);

            --font: 'Montserrat', 'Segoe UI', system-ui, sans-serif;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font);
            background:
                radial-gradient(1100px 500px at 50% -10%, rgba(244,152,37,0.10), transparent 60%),
                var(--navy-deep);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: var(--sp-4);
            color: var(--text);
        }

        .login-box {
            background: var(--panel-bg);
            border-radius: var(--radius);
            padding: var(--sp-5) var(--sp-5) var(--sp-4);
            width: 100%;
            max-width: 380px;
            box-shadow: var(--shadow);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            justify-content: center;
            margin-bottom: var(--sp-1);
        }
        .brand-icon {
            width: 34px; height: 34px;
            border-radius: 8px;
            background: var(--navy);
            display: flex; align-items: center; justify-content: center;
        }
        .brand-icon i { color: var(--orange); font-size: 16px; }
        .brand-title {
            font-size: 21px; font-weight: 700; color: var(--navy);
            letter-spacing: -0.4px;
        }
        .brand-title span { color: var(--orange); }

        .brand-sub {
            text-align: center;
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: var(--sp-5);
            font-weight: 500;
        }

        .field { margin-bottom: var(--sp-3); }
        .field label {
            display: block; font-size: 13px; font-weight: 600;
            color: var(--navy); margin-bottom: var(--sp-1);
        }
        .field input {
            width: 100%;
            padding: 12px 14px;
            border-radius: var(--radius-sm);
            background: var(--field-bg);
            color: var(--text);
            font-size: 14px;
            font-family: var(--font);
            outline: none;
            border: 1.5px solid var(--field-border);
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .field input:focus {
            border-color: var(--orange);
            box-shadow: 0 0 0 3px rgba(244,152,37,0.18);
        }

        .btn-login {
            width: 100%;
            padding: 13px;
            border-radius: var(--radius-sm);
            border: none;
            background: var(--orange);
            color: #fff;
            font-weight: 700;
            font-size: 14px;
            font-family: var(--font);
            cursor: pointer;
            margin-top: var(--sp-2);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.18s ease;
        }
        .btn-login:hover { background: var(--orange-hover); }
        .btn-login:hover:not(:disabled) { background: var(--orange-hover); }
        .btn-login:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .btn-login:focus-visible {
            outline: 3px solid rgba(244,152,37,0.5);
            outline-offset: 2px;
        }

        .alert {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 11px 14px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 500;
            margin-bottom: var(--sp-4);
            line-height: 1.45;
        }
        .alert-error {
            background: var(--error-bg);
            border-left: 3px solid var(--error-border);
            color: var(--error-text);
        }
        .alert-warn {
            background: var(--warn-bg);
            border-left: 3px solid var(--warn-border);
            color: var(--warn-text);
        }
        .alert i { margin-top: 2px; flex-shrink: 0; }

        .field input:focus-visible,
        a:focus-visible {
            outline: 3px solid rgba(244,152,37,0.5);
            outline-offset: 2px;
        }
    </style>
</head>
<body>
    <main class="login-box">
        <div class="brand">
            <span class="brand-icon" aria-hidden="true"><i class="fas fa-server"></i></span>
            <span class="brand-title">Auca<span>Tek</span></span>
        </div>
        <p class="brand-sub">Panel de administración</p>

        <?php if ($timeout_msg): ?>
            <div class="alert alert-warn" role="alert">
                <i class="fas fa-clock" aria-hidden="true"></i>
                <span><?php echo htmlspecialchars($timeout_msg); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error" role="alert">
                <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <form method="post" action="index.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(panel_csrf_token()); ?>">
            <div class="field">
                <label for="in-user">Usuario</label>
                <input type="text" id="in-user" name="user" placeholder="Ingresá tu usuario"
                       required <?php echo $bloqueado ? 'disabled' : 'autofocus'; ?>
                       autocomplete="username">
            </div>
            <div class="field">
                <label for="in-pass">Contraseña</label>
                <input type="password" id="in-pass" name="pass" placeholder="Ingresá tu contraseña"
                       required <?php echo $bloqueado ? 'disabled' : ''; ?>
                       autocomplete="current-password">
            </div>
            <button type="submit" class="btn-login"
                    <?php echo $bloqueado ? 'disabled' : ''; ?>
                    aria-label="Ingresar al panel de administración">
                <i class="fas fa-sign-in-alt" aria-hidden="true"></i> Ingresar
            </button>
        </form>
    </main>
</body>
</html>
