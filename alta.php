<?php
require_once 'auth.php';
require_once 'dbconn.php';

$mensaje_feedback = "";

if (isset($_POST['Enviar'])) {
    $csrf_token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    if (!validar_csrf_token($csrf_token)) {
        $mensaje_feedback = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-1'></i> La sesión del formulario expiró. Recargá la página e intentá de nuevo.</div>";
    } elseif (
        !empty($_POST['nombre']) && !empty($_POST['apellido']) &&
        !empty($_POST['email']) && !empty($_POST['User']) &&
        !empty($_POST['password']) && !empty($_POST['empresa']) &&
        !empty($_POST['codigo_pdu']) &&
        ($_POST['password'] === $_POST['Cpassword'])
    ) {
        $nombre     = mysqli_real_escape_string($conex, trim($_POST['nombre']));
        $apellido   = mysqli_real_escape_string($conex, trim($_POST['apellido']));
        $email      = mysqli_real_escape_string($conex, trim($_POST['email']));
        $User       = mysqli_real_escape_string($conex, trim($_POST['User']));
        $empresa    = mysqli_real_escape_string($conex, trim($_POST['empresa']));
        $codigo_pdu = mysqli_real_escape_string($conex, trim($_POST['codigo_pdu']));
        $password   = $_POST['password'];

        if (strlen($password) < 8) {
            $mensaje_feedback = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-1'></i> La contraseña debe tener al menos 8 caracteres.</div>";
        } else {
        $pass_cifrada = password_hash($password, PASSWORD_DEFAULT, array("cost" => 10));

        $verificar = "SELECT email, user FROM users WHERE email='$email' OR user='$User' LIMIT 1";
        $res_verificar = mysqli_query($conex, $verificar);

        if (mysqli_num_rows($res_verificar) > 0) {
            $mensaje_feedback = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-1'></i> El nombre de usuario o el email ya están registrados.</div>";
        } else {
            $consulta_insertar = "INSERT INTO users 
                (nombre, apellido, empresa, codigo_pdu, email, user, pass, rol, must_change_password) 
                VALUES 
                ('$nombre','$apellido','$empresa','$codigo_pdu','$email','$User','$pass_cifrada','viewer', 0)";
            $resultado = mysqli_query($conex, $consulta_insertar);

            if ($resultado) {
                $mensaje_feedback = "
                <div class='alert alert-success text-center'>
                    <h4><i class='fas fa-check-circle me-2' style='color:#f49825;'></i>Registro completado</h4>
                    <p>Serás redirigido al inicio de sesión en 3 segundos...</p>
                </div>
                <script>setTimeout(function(){ window.location.href='index.php'; }, 3000);</script>";
            } else {
                $mensaje_feedback = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-1'></i> Error inesperado en la base de datos.</div>";
            }
        }
        } // fin else (contraseña >= 8 chars)
    } else {
        $mensaje_feedback = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-1'></i> Completá todos los campos y verificá que las contraseñas coincidan.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro — SmartRACK</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
</head>
<body class="register-page">
<div class="register-container">

    <!-- [KATE] Título blanco puro — sin degradado -->
    <h2 style="color:#f49825; font-family:'Montserrat',sans-serif; font-weight:700;">Crear Cuenta SmartRACK</h2>

    <?php echo $mensaje_feedback; ?>

    <form action="alta.php" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generar_csrf_token()); ?>">

        <!-- [C02] Labels visibles en todos los campos -->
        <div class="row">
            <div class="col-md-6 form-group m-0">
                <label class="field-label" for="reg-nombre">Nombre</label>
                <input type="text" id="reg-nombre" name="nombre" class="form-control-netflix w-100" placeholder="Ingrese su Nombre" required>
            </div>
            <div class="col-md-6 form-group m-0">
                <label class="field-label" for="reg-apellido">Apellido</label>
                <input type="text" id="reg-apellido" name="apellido" class="form-control-netflix w-100" placeholder="Ingrese su Apellido" required>
            </div>
        </div>

        <div class="form-group m-0">
            <label class="field-label" for="reg-email">Correo Electrónico</label>
            <input type="email" id="reg-email" name="email" class="form-control-netflix w-100" placeholder="Ingrese su Correo Electrónico" required>
        </div>

        <div class="form-group m-0">
            <label class="field-label" for="reg-empresa">Empresa</label>
            <input type="text" id="reg-empresa" name="empresa" class="form-control-netflix w-100" placeholder="Ingrese el nombre de su Empresa" required>
        </div>

        <div class="form-group m-0">
            <label class="field-label" for="reg-user">Usuario</label>
            <input type="text" id="reg-user" name="User" class="form-control-netflix w-100" placeholder="Ingrese su Nombre de Usuario" required>
        </div>

        <div class="form-group m-0">
            <label class="field-label" for="reg-pass">Contraseña</label>
            <input type="password" id="reg-pass" name="password" class="form-control-netflix w-100"
                   placeholder="Mínimo 8 caracteres" minlength="8" required>
            <small id="pass-hint" style="color:rgba(255,255,255,0.6); font-size:11px; margin-top:3px; display:block;">
                Mínimo 8 caracteres
            </small>
        </div>

        <div class="form-group m-0">
            <label class="field-label" for="reg-cpass">Confirmar Contraseña</label>
            <input type="password" id="reg-cpass" name="Cpassword" class="form-control-netflix w-100" placeholder="Repetir contraseña" required>
        </div>

        <div class="form-group m-0">
            <label class="field-label" for="reg-pdu">Código del PDU</label>
            <input type="text" id="reg-pdu" name="codigo_pdu" class="form-control-netflix w-100" placeholder="Código del PDU comprado" required>
        </div>

        <button type="submit" name="Enviar" class="btn btn-netflix btn-block">Crear Cuenta</button>
    </form>

    <div class="login-now">
        ¿Ya tenés cuenta? <a href="index.php">Iniciá sesión aquí</a>.
    </div>
</div>
<script>
(function () {
    const passInput  = document.getElementById('reg-pass');
    const cpassInput = document.getElementById('reg-cpass');
    const hint       = document.getElementById('pass-hint');

    function checkPass() {
        if (passInput.value.length > 0 && passInput.value.length < 8) {
            hint.textContent = 'La contraseña debe tener al menos 8 caracteres.';
            hint.style.color = '#F87171';
            passInput.style.borderColor = '#F87171';
        } else {
            hint.textContent = 'Mínimo 8 caracteres';
            hint.style.color = 'rgba(255,255,255,0.5)';
            passInput.style.borderColor = '';
        }
    }

    passInput.addEventListener('input', checkPass);
})();
</script>
</body>
</html>