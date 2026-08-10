<?php
session_start();

// Destruir todas las variables de sesión de forma limpia
$_SESSION = array();

// Si se desea destruir la cookie de sesión, este es el momento
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finalmente, destruir la sesión en el servidor
session_destroy();

// Redirigir al inicio del sistema de inmediato
header("Location: index.php");
exit();
?>