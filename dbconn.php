<?php
// ============================================================
//  SmartRACK — dbconn.php
//  Conexión a la base de datos.
//
//  Desarrollo (XAMPP): variables hardcodeadas como fallback.
//  Producción (PlanetScale): variables cargadas desde .env
//
//  PlanetScale requiere SSL obligatorio — se configura
//  automáticamente cuando DB_SSL=true en el .env.
// ============================================================

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Cargar .env si existe (producción y desarrollo con .env configurado)
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// ── Credenciales ─────────────────────────────────────────────
// safeLoad() no lanza error si no existe el .env,
// así que si no hay .env caemos a los defaults de XAMPP.
$host     = $_ENV['DB_HOST']     ?? 'localhost';
$db_user  = $_ENV['DB_USER']     ?? 'root';
$password = $_ENV['DB_PASS']     ?? '';
$database = $_ENV['DB_NAME']     ?? 'smartrack';
$db_ssl   = ($_ENV['DB_SSL']     ?? 'false') === 'true';
$db_port  = (int) ($_ENV['DB_PORT'] ?? 3306);

// ── Conexión ─────────────────────────────────────────────────
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conex = mysqli_connect($host, $db_user, $password, $database, $db_port);
    mysqli_set_charset($conex, 'utf8mb4');

    // SSL obligatorio para PlanetScale
    // El certificado CA se descarga del panel de PlanetScale
    // y se guarda en /smartrack/ssl/cacert.pem
    if ($db_ssl) {
        $ssl_ca = __DIR__ . '/ssl/cacert.pem';
        mysqli_ssl_set($conex, null, null, $ssl_ca, null, null);
    }

} catch (mysqli_sql_exception $e) {
    http_response_code(503);
    die(json_encode([
        'success' => false,
        'error'   => 'Error de conexión a la base de datos.'
    ]));
}
?>