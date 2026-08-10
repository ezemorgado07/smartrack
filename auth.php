<?php
session_start();

function usuario_autenticado()
{
    return isset($_SESSION['usuario']);
}

function rol_actual()
{
    return isset($_SESSION['rol']) ? $_SESSION['rol'] : 'viewer';
}

function redirigir_por_rol($rol)
{
    $destinos = array(
        'admin'    => 'dashboard_admin.php',
        'operator' => 'dashboard_operator.php',
        'viewer'   => 'dashboard_viewer.php'
    );

    $destino = isset($destinos[$rol]) ? $destinos[$rol] : $destinos['viewer'];
    header("Location: " . $destino);
    exit();
}

function requerir_login()
{
    if (!usuario_autenticado()) {
        header("Location: index.php");
        exit();
    }
}

function requerir_rol($roles_permitidos)
{
    requerir_login();

    if (!in_array(rol_actual(), $roles_permitidos)) {
        redirigir_por_rol(rol_actual());
    }
}

function nombre_usuario_actual()
{
    return isset($_SESSION['nombre_completo']) ? $_SESSION['nombre_completo'] : $_SESSION['usuario'];
}

function inicial_usuario_actual()
{
    return strtoupper(substr($_SESSION['usuario'], 0, 1));
}

/**
 * Retorna true si el usuario debe cambiar su contraseña en este login.
 */
function debe_cambiar_password()
{
    return isset($_SESSION['must_change_password']) && $_SESSION['must_change_password'] == 1;
}

/**
 * Retorna true si el rol actual puede controlar tomas (admin u operator).
 */
function puede_controlar_tomas()
{
    return in_array(rol_actual(), array('admin', 'operator'));
}

/* ==========================================================
   RATE LIMITING - LOGIN (tarea prioritaria #2)
   ========================================================== */

const RATE_LIMIT_MAX_INTENTOS   = 5;
const RATE_LIMIT_VENTANA_MIN    = 10; // minutos
const RATE_LIMIT_BLOQUEO_MIN    = 15; // minutos

/**
 * Elimina registros de login_attempts con más de 24hs de antigüedad,
 * para que la tabla no crezca indefinidamente.
 */
function limpiar_registros_viejos(mysqli $conex): void
{
    mysqli_query($conex, "DELETE FROM login_attempts WHERE primer_intento < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
}

/**
 * Verifica si la IP dada está actualmente bloqueada por exceso de intentos
 * fallidos. Si lo está, muestra un mensaje de bloqueo y termina la
 * ejecución (die). Si no lo está, retorna normalmente y el flujo de
 * ingreso.php continúa.
 *
 * IMPORTANTE: se usa $_SERVER['REMOTE_ADDR'] exclusivamente. Headers como
 * X-Forwarded-For NO se usan porque pueden ser falsificados por el cliente.
 */
function verificar_rate_limit(mysqli $conex, string $ip): void
{
    limpiar_registros_viejos($conex);

    $ip_esc = mysqli_real_escape_string($conex, $ip);
    $sql    = "SELECT UNIX_TIMESTAMP(bloqueado_hasta) AS bloqueado_hasta_ts FROM login_attempts WHERE ip = '$ip_esc' LIMIT 1";
    $result = mysqli_query($conex, $sql);

    if ($result && mysqli_num_rows($result) == 1) {
        $row = mysqli_fetch_assoc($result);

        if ($row['bloqueado_hasta_ts'] !== null) {
            $bloqueado_hasta_ts = (int) $row['bloqueado_hasta_ts'];

            if (time() < $bloqueado_hasta_ts) {
                $minutos_restantes = (int) ceil(($bloqueado_hasta_ts - time()) / 60);
                mysqli_close($conex);
                mostrar_pagina_bloqueado($minutos_restantes);
            }
        }
    }
}

/**
 * Registra un intento fallido de login para la IP dada. Si la IP supera
 * el máximo de intentos dentro de la ventana de tiempo, la bloquea.
 * Si la ventana anterior ya venció, la reinicia.
 */
function registrar_intento_fallido(mysqli $conex, string $ip): void
{
    $ip_esc = mysqli_real_escape_string($conex, $ip);
    $sql    = "SELECT id, intentos, UNIX_TIMESTAMP(primer_intento) AS primer_intento_ts FROM login_attempts WHERE ip = '$ip_esc' LIMIT 1";
    $result = mysqli_query($conex, $sql);

    if ($result && mysqli_num_rows($result) == 1) {
        $row               = mysqli_fetch_assoc($result);
        $id                = (int) $row['id'];
        $primer_intento_ts = (int) $row['primer_intento_ts'];
        $ventana_vencida   = (time() - $primer_intento_ts) > (RATE_LIMIT_VENTANA_MIN * 60);

        if ($ventana_vencida) {
            // La ventana anterior venció: se reinicia el conteo.
            mysqli_query($conex, "UPDATE login_attempts SET intentos = 1, primer_intento = NOW(), bloqueado_hasta = NULL WHERE id = $id");
        } else {
            $nuevos_intentos = (int) $row['intentos'] + 1;

            if ($nuevos_intentos >= RATE_LIMIT_MAX_INTENTOS) {
                $bloqueo_min = RATE_LIMIT_BLOQUEO_MIN;
                mysqli_query($conex, "UPDATE login_attempts SET intentos = $nuevos_intentos, bloqueado_hasta = DATE_ADD(NOW(), INTERVAL $bloqueo_min MINUTE) WHERE id = $id");
            } else {
                mysqli_query($conex, "UPDATE login_attempts SET intentos = $nuevos_intentos WHERE id = $id");
            }
        }
    } else {
        mysqli_query($conex, "INSERT INTO login_attempts (ip, intentos, primer_intento) VALUES ('$ip_esc', 1, NOW())");
    }
}

/**
 * Resetea el contador de intentos fallidos de una IP tras un login exitoso.
 */
function resetear_intentos(mysqli $conex, string $ip): void
{
    $ip_esc = mysqli_real_escape_string($conex, $ip);
    mysqli_query($conex, "DELETE FROM login_attempts WHERE ip = '$ip_esc'");
}

/**
 * Muestra una página de bloqueo con estilo AucaTek y termina la ejecución.
 */
function mostrar_pagina_bloqueado(int $minutos_restantes): void
{
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    $texto_minutos = $minutos_restantes == 1 ? "1 minuto" : "$minutos_restantes minutos";
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Acceso temporalmente bloqueado - SmartRACK</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body {
                font-family: 'Montserrat', Arial, sans-serif;
                background-color: #23264f;
                color: #ffffff;
                display: flex;
                align-items: center;
                justify-content: center;
                height: 100vh;
                margin: 0;
            }
            .bloqueo-card {
                background-color: #ffffff;
                color: #23264f;
                border-top: 6px solid #f49825;
                border-radius: 8px;
                padding: 40px;
                max-width: 420px;
                text-align: center;
                box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            }
            .bloqueo-card i {
                color: #f49825;
                font-size: 42px;
                margin-bottom: 16px;
            }
            .bloqueo-card h1 {
                font-size: 20px;
                margin: 0 0 12px 0;
            }
            .bloqueo-card p {
                font-size: 14px;
                line-height: 1.5;
                margin: 0 0 8px 0;
            }
            .bloqueo-card a {
                display: inline-block;
                margin-top: 16px;
                color: #5f7dbe;
                text-decoration: none;
                font-weight: 600;
            }
        </style>
    </head>
    <body>
        <div class="bloqueo-card">
            <h1>Acceso temporalmente bloqueado</h1>
            <p>Se detectaron demasiados intentos fallidos de inicio de sesión desde esta dirección.</p>
            <p>Por seguridad, el acceso está bloqueado por <strong><?php echo $texto_minutos; ?></strong> más.</p>
            <p>Volvé a intentarlo pasado ese tiempo.</p>
            <a href="index.php">Volver al inicio</a>
        </div>
    </body>
    </html>
    <?php
    exit();
}

/* ==========================================================
   PROTECCIÓN CSRF (tarea prioritaria #3)
   ========================================================== */

/**
 * Retorna el token CSRF de la sesión actual, generándolo si no existe.
 * El mismo token se reutiliza durante toda la sesión del usuario.
 */
function generar_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Valida el token recibido contra el token almacenado en sesión.
 * Usa hash_equals() para evitar timing attacks.
 */
function validar_csrf_token(string $token_recibido): bool
{
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token_recibido);
}
?>