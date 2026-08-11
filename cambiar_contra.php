<?php
require_once 'auth.php';
require_once 'dbconn.php';
enviar_headers_seguridad();

if (!isset($_SESSION['usuario'])) {
    header("Location: index.php");
    exit();
}

$mensaje_feedback = "";
$usuario_activo   = $_SESSION['usuario'];

if (isset($_POST['Cambiar'])) {
    $csrf_token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    if (!validar_csrf_token($csrf_token)) {
        $mensaje_feedback = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-1'></i> La sesión del formulario expiró. Recargá la página e intentá de nuevo.</div>";
    } elseif (!empty($_POST['pass_actual']) && is_string($_POST['pass_actual']) &&
              !empty($_POST['pass_nueva'])  && is_string($_POST['pass_nueva'])  &&
              !empty($_POST['pass_conf'])   && is_string($_POST['pass_conf'])) {

        $pass_actual = $_POST['pass_actual'];
        $pass_nueva  = $_POST['pass_nueva'];
        $pass_conf   = $_POST['pass_conf'];

        if (strlen($pass_nueva) < 8) {
            $mensaje_feedback = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-1'></i> La nueva contraseña debe tener al menos 8 caracteres.</div>";
        } elseif ($pass_nueva !== $pass_conf) {
            $mensaje_feedback = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-1'></i> Las nuevas contraseñas no coinciden.</div>";
        } else {
            $usuario_clean = mysqli_real_escape_string($conex, $usuario_activo);

            $sql       = "SELECT pass FROM users WHERE user = '$usuario_clean' LIMIT 1";
            $resultado = mysqli_query($conex, $sql);

            if ($resultado && mysqli_num_rows($resultado) == 1) {
                $row      = mysqli_fetch_assoc($resultado);
                $hash_actual = $row['pass'];

                if (password_verify($pass_actual, $hash_actual)) {
                    $nuevo_hash = password_hash($pass_nueva, PASSWORD_DEFAULT, array("cost" => 10));
                    $nuevo_hash_sql = mysqli_real_escape_string($conex, $nuevo_hash);

                    $update_sql = "UPDATE users
                                   SET pass = '$nuevo_hash_sql',
                                       must_change_password = 0
                                   WHERE user = '$usuario_clean'";

                    if (mysqli_query($conex, $update_sql)) {
                        $_SESSION['must_change_password'] = 0;

                        $rol = rol_actual();
                        $mensaje_feedback = "
                        <div class='alert alert-success text-center'>
                            <h4><i class='fas fa-check-circle me-2' style='color:#f49825;'></i>Contraseña actualizada</h4>
                            <p>Serás redirigido al panel en 3 segundos...</p>
                        </div>
                        <script>
                            setTimeout(function(){
                                window.location.href = '" . htmlspecialchars(
                                    ($rol === 'admin'    ? 'dashboard_admin.php' :
                                    ($rol === 'operator' ? 'dashboard_operator.php' : 'dashboard_viewer.php'))
                                ) . "';
                            }, 3000);
                        </script>";
                    } else {
                        $mensaje_feedback = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-1'></i> Error interno al actualizar la base de datos.</div>";
                    }
                } else {
                    $mensaje_feedback = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-1'></i> La contraseña actual introducida es incorrecta.</div>";
                }
            } else {
                $mensaje_feedback = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-1'></i> Error al identificar el usuario activo.</div>";
            }
        }
    } else {
        $mensaje_feedback = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-1'></i> Por favor, complete todos los campos obligatorios.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambiar Contraseña — SmartRACK</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
</head>
<body class="password-page">

    <div class="crypto-container">

        <!-- [P03] Título sin emoji, blanco puro -->
        <h2 style="color:#ffffff; font-family:'Montserrat',sans-serif; font-weight:700;">Cambiar Contraseña</h2>

        <?php if (debe_cambiar_password()): ?>
        <div class="alert" style="background-color: #1a3a5c; border-left: 4px solid #f49825; font-size: 13px; margin-bottom: 16px;">
            <!-- [P03] Ícono FA en lugar de emoji 🔒 -->
            Por seguridad, debes establecer una nueva contraseña antes de continuar.
        </div>
        <?php endif; ?>

        <?php echo $mensaje_feedback; ?>

        <form action="cambiar_contra.php" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generar_csrf_token()); ?>">

            <!-- [C01] Labels con class="field-label" — blanco visible -->
            <div class="form-group m-0">
                <label class="field-label">Contraseña Actual</label>
                <input type="password" name="pass_actual" class="form-control-netflix w-100"
                       placeholder="Introduce tu clave actual" required>
            </div>
            <div class="form-group m-0">
                <label class="field-label">Nueva Contraseña</label>
                <input type="password" name="pass_nueva" id="pass_nueva" class="form-control-netflix w-100"
                       placeholder="Mínimo 8 caracteres" minlength="8" required>
            </div>
            <div class="form-group m-0">
                <label class="field-label">Confirmar Nueva Contraseña</label>
                <input type="password" name="pass_conf" id="pass_conf" class="form-control-netflix w-100"
                       placeholder="Repite la nueva contraseña" minlength="8" required>
            </div>

            <button type="submit" name="Cambiar" class="btn btn-netflix btn-block">Guardar Cambios</button>
        </form>

        <?php if (!debe_cambiar_password()): ?>
        <a href="<?php
            $r = rol_actual();
            echo $r === 'admin' ? 'dashboard_admin.php' : ($r === 'operator' ? 'dashboard_operator.php' : 'dashboard_viewer.php');
        ?>" class="back-link" style="display:block; text-align:center; margin-top:16px; color:var(--at-orange); font-size:13px;">
            &larr; Volver al Panel
        </a>
        <?php endif; ?>
    </div>

    <script>
        // Validación client-side: mínimo 8 caracteres y que coincidan
        document.querySelector('form').addEventListener('submit', function(e) {
            var nueva  = document.getElementById('pass_nueva').value;
            var conf   = document.getElementById('pass_conf').value;

            if (nueva.length < 8) {
                e.preventDefault();
                alert('La nueva contraseña debe tener al menos 8 caracteres.');
                return;
            }
            if (nueva !== conf) {
                e.preventDefault();
                alert('Las contraseñas nuevas no coinciden.');
            }
        });
    </script>
</body>
</html>