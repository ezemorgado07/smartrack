<?php
// ============================================================
//  SmartRACK — mqtt_test.php
//  Endpoint TEMPORAL para probar la conexión al broker MQTT.
//  Publica un mensaje en test/ping y devuelve el resultado.
//
//  ⚠ BORRAR ESTE ARCHIVO DESPUÉS DE LAS PRUEBAS ⚠
//
//  Uso: abrir en el navegador
//    http://localhost/smartrack/mqtt_test.php
// ============================================================

ob_start();
error_reporting(0);
ini_set('display_errors', 0);

use PhpMqtt\Client\MqttClient as PhpMqttClient;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Exceptions\MqttClientException;
use Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

ob_clean();
header('Content-Type: application/json');

$mqtt_host = $_ENV['MQTT_HOST'] ?? '';
$mqtt_port = (int) ($_ENV['MQTT_PORT'] ?? 8883);
$mqtt_user = $_ENV['MQTT_USER'] ?? '';
$mqtt_pass = $_ENV['MQTT_PASS'] ?? '';

$resultado = [
    'broker'    => "$mqtt_host:$mqtt_port",
    'timestamp' => date('Y-m-d H:i:s'),
];

try {
    $client_id = 'smartrack_test_' . uniqid();
    $mqtt = new PhpMqttClient($mqtt_host, $mqtt_port, $client_id);

    $settings = (new ConnectionSettings())
        ->setUsername($mqtt_user)
        ->setPassword($mqtt_pass)
        ->setUseTls(true)
        ->setTlsSelfSignedAllowed(false)
        ->setConnectTimeout(10);

    $t_inicio = microtime(true);
    $mqtt->connect($settings, true);
    $t_conexion = round((microtime(true) - $t_inicio) * 1000, 1);

    $payload = json_encode([
        'test'      => true,
        'from'      => 'mqtt_test.php',
        'timestamp' => date('Y-m-d H:i:s'),
    ]);

    $mqtt->publish('test/ping', $payload, 1);
    $mqtt->disconnect();

    echo json_encode(array_merge($resultado, [
        'success'           => true,
        'message'           => 'Conexión y publicación exitosas.',
        'tiempo_conexion_ms'=> $t_conexion,
        'topico_publicado'  => 'test/ping',
        'payload'           => json_decode($payload, true),
    ]), JSON_PRETTY_PRINT);

} catch (MqttClientException $e) {
    echo json_encode(array_merge($resultado, [
        'success' => false,
        'error'   => 'Error MQTT: ' . $e->getMessage(),
    ]), JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(array_merge($resultado, [
        'success' => false,
        'error'   => 'Error: ' . $e->getMessage(),
    ]), JSON_PRETTY_PRINT);
}
?>
