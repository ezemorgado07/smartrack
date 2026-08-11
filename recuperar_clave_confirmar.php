<?php
require_once 'auth.php';
enviar_headers_seguridad();

$exito   = false;
$mensaje_titulo = '';
$mensaje_sub    = '';

if (isset($_GET['e']) && isset($_GET['t']) && is_string($_GET['e']) && is_string($_GET['t'])) {

    require_once 'dbconn.php';

    // SEGURIDAD: Sanitizamos los parámetros GET para evitar Inyecciones SQL
    $email = mysqli_real_escape_string($conex, $_GET['e']);
    $token = mysqli_real_escape_string($conex, $_GET['t']);

    // Buscar la solicitud correspondiente en la tabla de recuperación
    $c = "SELECT CLAVE_NUEVA FROM recuperar WHERE email='$email' AND TOKEN='$token' LIMIT 1";
    $f = mysqli_query($conex, $c);
    $a = mysqli_fetch_assoc($f);

    if (!$a) {
        mysqli_close($conex);
        $mensaje_titulo = 'Solicitud inválida';
        $mensaje_sub    = 'La solicitud de recuperación no es válida o ya caducó.';
    } else {
        // Obtener la clave temporal guardada en la tabla de recuperación
        $clave = $a['CLAVE_NUEVA'];

        // Encriptar la contraseña de forma segura
        $clave_ = password_hash($clave, PASSWORD_DEFAULT, array("cost" => 10));

        // Actualizar la tabla "users" + forzar cambio en el próximo login
        $c2 = "UPDATE users SET pass='$clave_', must_change_password=1 WHERE email='$email' LIMIT 1";
        mysqli_query($conex, $c2);

        // Consumir el token (borrar la solicitud para que no pueda volver a usarse)
        $c3 = "DELETE FROM recuperar WHERE email='$email' LIMIT 1";
        mysqli_query($conex, $c3);

        mysqli_close($conex);

        $exito = true;
        $mensaje_titulo = 'Contraseña Actualizada';
        $mensaje_sub    = 'Tu contraseña temporal ha sido activada con éxito. Ya podés iniciar sesión usando la contraseña numérica provista en el email.';
    }

} else {
    $mensaje_titulo = 'Parámetros insuficientes';
    $mensaje_sub    = 'El enlace no contiene la información necesaria para procesar la solicitud.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $exito ? 'Contraseña actualizada' : 'Solicitud inválida'; ?> — SmartRACK</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <style>
        .sr-confirm-icon {
            width: 56px; height: 56px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 18px;
            font-size: 24px;
        }
        .sr-confirm-icon.success {
            background: rgba(110,231,183,0.15);
            border: 1px solid rgba(110,231,183,0.40);
            color: #6EE7B7;
        }
        .sr-confirm-icon.error {
            background: rgba(248,113,113,0.15);
            border: 1px solid rgba(248,113,113,0.40);
            color: #F87171;
        }
        .sr-confirm-title {
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
            font-size: 22px;
            color: #ffffff;
            text-align: center;
            margin-bottom: 10px;
        }
        .sr-confirm-sub {
            font-family: 'Montserrat', sans-serif;
            font-size: 13px;
            color: rgba(255,255,255,0.65);
            text-align: center;
            line-height: 1.6;
            margin-bottom: 26px;
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

        <div class="sr-confirm-icon <?php echo $exito ? 'success' : 'error'; ?>">
            <i class="fas <?php echo $exito ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
        </div>

        <div class="sr-confirm-title"><?php echo htmlspecialchars($mensaje_titulo); ?></div>
        <p class="sr-confirm-sub"><?php echo htmlspecialchars($mensaje_sub); ?></p>

        <?php if ($exito): ?>
            <a href="index.php" class="btn btn-netflix btn-block">
                <i class="fas fa-sign-in-alt me-2"></i>Ir al Login
            </a>
        <?php else: ?>
            <a href="recuperar_clave.php" class="btn btn-netflix btn-block">
                <i class="fas fa-redo me-2"></i>Solicitar otra clave
            </a>
            <div class="login-now">
                <a href="index.php">Volver al login</a>
            </div>
        <?php endif; ?>

    </div>

</body>
</html>