<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartRACK — Iniciar Sesión</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <style>
        .password-wrapper {
            position: relative;
            width: 100%;
        }
        .password-wrapper .form-control-netflix {
            padding-right: 48px;
        }
        .btn-toggle-password {
            position: absolute;
            top: 50%;
            right: 14px;
            transform: translateY(-50%);
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            color: rgba(255,255,255,0.45);
            font-size: 15px;
            line-height: 1;
            transition: color 0.2s;
        }
        .btn-toggle-password:hover,
        .btn-toggle-password:focus {
            color: #f49825;
            outline: none;
        }

        /* ── Brand AucaTek ── */
        .sr-login-brand {
            text-align: center;
            margin-bottom: 24px;
        }
        .sr-login-logo {
            width: 190px;
            height: auto;
            display: block;
            margin: 0 auto 14px;
            /* Leve filtro para que el isotipo naranja/celeste brille más sobre el fondo */
            filter: drop-shadow(0 2px 8px rgba(0,0,0,0.35));
        }
        .sr-login-divider {
            width: 40px;
            height: 2px;
            background: #f49825;
            border: none;
            margin: 0 auto 16px;
            border-radius: 2px;
        }
        .sr-login-product {
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
            font-size: 22px;
            color: #ffffff;
            letter-spacing: -0.3px;
            margin-bottom: 2px;
        }
        .sr-login-sub {
            font-family: 'Montserrat', sans-serif;
            font-size: 12px;
            font-weight: 400;
            color: rgba(255,255,255,0.50);
            margin-bottom: 0;
        }

        /* ── Alert error inline ── */
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
        .sr-alert-error-text { flex: 1; }
        .sr-alert-error-title {
            color: #ffffff;
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 2px;
        }
        .sr-alert-error-sub {
            color: rgba(255,255,255,0.65);
            font-size: 12px;
            font-family: 'Montserrat', sans-serif;
        }
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

        <!-- Brand AucaTek -->
        <div class="sr-login-brand">
            <img src="assets/img/logo.png"
                 alt="AucaTek — innovate IT"
                 class="sr-login-logo">
            <hr class="sr-login-divider">
            <div class="sr-login-product">SmartRACK</div>
            <p class="sr-login-sub">Portal de Gestión Energética</p>
        </div>

        <?php if (isset($_GET['error'])): ?>
        <div class="sr-alert-error" role="alert">
            <i class="fas fa-exclamation-circle"></i>
            <div class="sr-alert-error-text">
                <div class="sr-alert-error-title">Credenciales incorrectas</div>
                <div class="sr-alert-error-sub">Verificá tu usuario y contraseña e intentá nuevamente.</div>
            </div>
        </div>
        <?php endif; ?>

        <form action="ingreso.php" method="post">

            <label class="field-label" for="input-usuario">Usuario</label>
            <input type="text" id="input-usuario" name="usuario"
                   class="form-control-netflix w-100"
                   placeholder="Ingresá tu usuario"
                   required autocomplete="username"
                   <?php if (isset($_GET['error'])): ?>autofocus<?php endif; ?>>

            <label class="field-label" for="input-password">Contraseña</label>
            <div class="password-wrapper">
                <input type="password" id="input-password" name="password"
                       class="form-control-netflix w-100"
                       placeholder="••••••••"
                       required autocomplete="current-password">
                <button type="button"
                        class="btn-toggle-password"
                        id="btn-toggle-pass"
                        aria-label="Mostrar u ocultar contraseña"
                        title="Mostrar / ocultar contraseña">
                    <i class="fas fa-eye" id="icon-toggle-pass"></i>
                </button>
            </div>

            <button type="submit" name="Enviar" class="btn btn-netflix btn-block">
                <i class="fas fa-sign-in-alt me-2"></i> Iniciar Sesión
            </button>

            <div class="login-help mt-3">
                <a href="recuperar_clave.php">¿Olvidaste tu contraseña?</a>
            </div>
        </form>

        <div class="signup-now">
            ¿Primera vez? <a href="alta.php">Registrate aquí</a>
        </div>

    </div>

    <script>
        (function () {
            var btn   = document.getElementById('btn-toggle-pass');
            var input = document.getElementById('input-password');
            var icon  = document.getElementById('icon-toggle-pass');

            btn.addEventListener('click', function () {
                var isPassword = input.type === 'password';
                input.type     = isPassword ? 'text' : 'password';
                icon.className = isPassword ? 'fas fa-eye-slash' : 'fas fa-eye';
                btn.title      = isPassword ? 'Ocultar contraseña' : 'Mostrar contraseña';
            });
        })();
    </script>

</body>
</html>