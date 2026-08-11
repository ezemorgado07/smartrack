<?php
ob_start();

require_once 'auth.php';
require_once 'dbconn.php';

// IP real del cliente. No se usa X-Forwarded-For ni ningún otro header,
// ya que pueden ser falsificados por el cliente.
$ip = $_SERVER['REMOTE_ADDR'];

// Si la IP está bloqueada por exceso de intentos fallidos, esta función
// muestra el mensaje de bloqueo y termina la ejecución.
verificar_rate_limit($conex, $ip);

if (isset($_POST['Enviar'])) {
    $usuario_raw = trim($_POST['usuario']);
    $usuario     = mysqli_real_escape_string($conex, $usuario_raw);
    $password    = $_POST['password'];

    $sql    = "SELECT * FROM users WHERE user = '$usuario' AND is_active = 1 LIMIT 1";
    $result = mysqli_query($conex, $sql);

    $login_exitoso = false;

    if (mysqli_num_rows($result) == 1) {
        $row            = mysqli_fetch_assoc($result);
        $hashedPassword = $row['pass'];

        if (password_verify($password, $hashedPassword)) {
            $login_exitoso = true;

            resetear_intentos($conex, $ip);
            registrar_security_log($conex, 'login_exitoso', $ip, $row['user']);

            $_SESSION['usuario']              = $row['user'];
            $_SESSION['usuario_id']           = $row['id'];
            $_SESSION['nombre_completo']      = $row['nombre'] . ' ' . $row['apellido'];
            $_SESSION['rol']                  = $row['rol'];
            $_SESSION['must_change_password'] = (int) $row['must_change_password'];

            $uid = (int) $row['id'];
            mysqli_query($conex, "UPDATE users SET last_login_at = NOW() WHERE id = $uid");
            mysqli_close($conex);

            if ($_SESSION['must_change_password'] == 1) {
                header("Location: cambiar_contra.php");
                exit();
            }

            redirigir_por_rol($_SESSION['rol']);
        }
    }

    if (!$login_exitoso) {
        registrar_intento_fallido($conex, $ip);
        registrar_security_log($conex, 'login_fallido', $ip, $usuario_raw);
        mysqli_close($conex);
        header("Location: index.php?error=1");
        exit();
    }
}

mysqli_close($conex);
header("Location: index.php");
exit();
?>