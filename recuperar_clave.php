<?php
require_once 'auth.php';
enviar_headers_seguridad();

$status = isset($_GET['status']) ? $_GET['status'] : null;
$email_enviado = (isset($_GET['email']) && is_string($_GET['email'])) ? htmlspecialchars($_GET['email']) : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña — SmartRACK</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <style>
        /* ── Alert genérico inline (éxito / error) — paleta AucaTek ── */
        .sr-alert-success {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: rgba(39,103,73,0.18);
            border: 1px solid rgba(110,231,183,0.45);
            border-left: 3px solid #6EE7B7;
            border-radius: 6px;
            padding: 12px 14px;
            margin-bottom: 18px;
        }
        .sr-alert-success i {
            color: #6EE7B7;
            font-size: 15px;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .sr-alert-error {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: rgba(197,48,48,0.18);
            border: 1px solid rgba(248,113,113,0.45);
            border-left: 3px solid #F87171;
            border-radius: 6px;
            padding: 12px 14px;
            margin-bottom: 18px;
            animation: sr-shake 0.35s ease;
        }
        .sr-alert-error i {
            color: #F87171;
            font-size: 15px;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .sr-alert-text { flex: 1; }
        .sr-alert-title {
            color: #ffffff;
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 2px;
        }
        .sr-alert-sub {
            color: rgba(255,255,255,0.65);
            font-size: 12px;
            font-family: 'Montserrat', sans-serif;
        }
        .sr-alert-sub strong { color: #fff; }
        @keyframes sr-shake {
            0%  { transform: translateX(0); }
            20% { transform: translateX(-6px); }
            40% { transform: translateX(6px); }
            60% { transform: translateX(-4px); }
            80% { transform: translateX(4px); }
            100%{ transform: translateX(0); }
        }
    </style>
</head>
<body class="login-page">

    <div class="login-container">

        <div class="sr-login-brand">
            <img src="assets/img/logo.png"
                 alt="AucaTek — innovate IT"
                 class="sr-login-logo"
                 style="width: 190px; height: auto; display: block; margin: 0 auto;">
            <hr class="sr-login-divider" style="margin-top: 18px; margin-bottom: 22px;">
        </div>

        <h2 class="text-center" style="color:#ffffff; font-family:'Montserrat',sans-serif; font-weight:700;">
            Recuperar Contraseña
        </h2>
        <p class="text-center mb-4" style="color:rgba(255,255,255,0.55); font-size:13px; margin-top:-8px;">
            Ingresá tu correo y te enviamos una contraseña temporal.
        </p>

        <?php if ($status === 'enviado'): ?>
        <div class="sr-alert-success" role="alert">
            <i class="fas fa-check-circle"></i>
            <div class="sr-alert-text">
                <div class="sr-alert-title">Correo enviado</div>
                <div class="sr-alert-sub">
                    Enviamos un enlace de confirmación a <strong><?php echo $email_enviado; ?></strong>
                    con tu nueva contraseña temporal. Revisá tu bandeja de entrada (y spam).
                </div>
            </div>
        </div>
        <?php elseif ($status === 'no_registrado'): ?>
        <div class="sr-alert-error" role="alert">
            <i class="fas fa-exclamation-circle"></i>
            <div class="sr-alert-text">
                <div class="sr-alert-title">Correo no registrado</div>
                <div class="sr-alert-sub">El correo ingresado no pertenece a ningún usuario registrado.</div>
            </div>
        </div>
        <?php elseif ($status === 'error_db'): ?>
        <div class="sr-alert-error" role="alert">
            <i class="fas fa-exclamation-circle"></i>
            <div class="sr-alert-text">
                <div class="sr-alert-title">Error interno</div>
                <div class="sr-alert-sub">No se pudo procesar la solicitud. Intentá nuevamente.</div>
            </div>
        </div>
        <?php elseif ($status === 'error_envio'): ?>
        <div class="sr-alert-error" role="alert">
            <i class="fas fa-exclamation-circle"></i>
            <div class="sr-alert-text">
                <div class="sr-alert-title">Error al enviar el correo</div>
                <div class="sr-alert-sub">No pudimos enviar las instrucciones. Intentá nuevamente en unos minutos.</div>
            </div>
        </div>
        <?php elseif ($status === 'acceso_invalido'): ?>
        <div class="sr-alert-error" role="alert">
            <i class="fas fa-exclamation-circle"></i>
            <div class="sr-alert-text">
                <div class="sr-alert-title">Acceso no válido</div>
                <div class="sr-alert-sub">Ingresá tu correo electrónico para iniciar la recuperación.</div>
            </div>
        </div>
        <?php endif; ?>

        <form action="procesar_recupero.php" method="post">

            <label class="field-label" for="email">Correo Electrónico</label>
            <input type="email" name="email" id="email"
                   class="form-control-netflix w-100"
                   placeholder="ejemplo@correo.com" required autocomplete="email"
                   value="<?php echo $email_enviado; ?>">

            <button type="submit" class="btn btn-netflix btn-block mt-3">
                <i class="fas fa-paper-plane me-2"></i>Enviar Instrucciones
            </button>

        </form>

        <div class="login-now">
            ¿Recordaste tu clave? <a href="index.php">Iniciá sesión aquí</a>
        </div>

    </div>

</body>
</html>