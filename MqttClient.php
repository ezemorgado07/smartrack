<?php
// ============================================================
//  SmartRACK — MqttClient.php
//  Wrapper de la librería php-mqtt/client para el broker
//  Mosquitto propio (sin TLS, puerto 1883).
//
//  Instalación:
//    composer require php-mqtt/client
//
//  Credenciales leídas desde .env:
//    MQTT_HOST, MQTT_PORT, MQTT_USER, MQTT_PASS
//
//  Uso:
//    $mqtt = new MqttClient();
//    $mqtt->publish('pdu/abc/comando/toma/1', '{"command":"on"}', 1);
//    $mqtt->disconnect();
// ============================================================

use PhpMqtt\Client\MqttClient as PhpMqttClient;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Exceptions\MqttClientException;

require_once __DIR__ . '/vendor/autoload.php';

class MqttClient
{
    private $client;
    private string $host;
    private int    $port;
    private string $user;
    private string $pass;
    private string $client_id;
    private bool   $connected = false;

    // Last Will Testament (opcional, configurar ANTES de connect())
    private ?string $lwt_topic   = null;
    private ?string $lwt_payload = null;
    private int     $lwt_qos     = 1;

    public function __construct(string $client_id_suffix = '')
    {
        // Cargar .env si no está cargado
        if (empty($_ENV['MQTT_HOST'])) {
            if (class_exists('Dotenv\\Dotenv')) {
                $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
                $dotenv->safeLoad();
            }
        }

        $this->host = $_ENV['MQTT_HOST'] ?? '';
        $this->port = (int) ($_ENV['MQTT_PORT'] ?? 1883);
        $this->user = $_ENV['MQTT_USER'] ?? '';
        $this->pass = $_ENV['MQTT_PASS'] ?? '';

        // client_id único para evitar colisiones en el broker
        $this->client_id = 'smartrack_' . ($client_id_suffix ?: uniqid());

        $this->client = new PhpMqttClient($this->host, $this->port, $this->client_id);
    }

    /**
     * Configuración de conexión sin TLS (broker Mosquitto propio).
     */
    private function buildSettings(): ConnectionSettings
    {
        $settings = (new ConnectionSettings())
            ->setUsername($this->user ?: null)
            ->setPassword($this->pass ?: null)
            ->setUseTls(false)
            ->setConnectTimeout(10)
            ->setKeepAliveInterval(60);

        if ($this->lwt_topic !== null) {
            $settings = $settings
                ->setLastWillTopic($this->lwt_topic)
                ->setLastWillMessage($this->lwt_payload ?? '')
                ->setLastWillQualityOfService($this->lwt_qos);
        }

        return $settings;
    }

    /**
     * Registra el Last Will Testament.
     * Debe llamarse ANTES de connect().
     */
    public function setLastWill(string $topic, string $payload, int $qos = 1): void
    {
        $this->lwt_topic   = $topic;
        $this->lwt_payload = $payload;
        $this->lwt_qos     = $qos;
    }

    /**
     * Conecta al broker. Idempotente: si ya está conectado no hace nada.
     */
    public function connect(): bool
    {
        if ($this->connected) return true;

        try {
            $this->client->connect($this->buildSettings(), true);
            $this->connected = true;
            return true;
        } catch (MqttClientException $e) {
            $this->connected = false;
            return false;
        }
    }

    /**
     * Publica un mensaje. Conecta automáticamente si hace falta.
     * Un reintento automático tras reconexión.
     *
     * @return bool true si se publicó correctamente
     */
    public function publish(string $topic, string $payload, int $qos = 1): bool
    {
        if (!$this->connected && !$this->connect()) {
            return false;
        }

        try {
            $this->client->publish($topic, $payload, $qos);
            return true;
        } catch (MqttClientException $e) {
            $this->connected = false;
            if ($this->connect()) {
                try {
                    $this->client->publish($topic, $payload, $qos);
                    return true;
                } catch (MqttClientException $e2) {
                    return false;
                }
            }
            return false;
        }
    }

    /**
     * Suscribe a un tópico con un callback (function($topic, $message)).
     * El loop se maneja externamente con loop().
     */
    public function subscribe(string $topic, callable $callback, int $qos = 1): bool
    {
        if (!$this->connected && !$this->connect()) {
            return false;
        }

        try {
            $this->client->subscribe($topic, $callback, $qos);
            return true;
        } catch (MqttClientException $e) {
            return false;
        }
    }

    /**
     * Loop de escucha — bloqueante. Usado por el worker.
     */
    public function loop(bool $allowSleep = true): void
    {
        $this->client->loop($allowSleep);
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function disconnect(): void
    {
        if ($this->connected) {
            try {
                $this->client->disconnect();
            } catch (MqttClientException $e) {
                // Silencioso
            }
            $this->connected = false;
        }
    }
}
?>
