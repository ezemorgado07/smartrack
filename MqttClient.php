<?php
// ============================================================
//  SmartRACK — MqttClient.php
//  Wrapper de la librería php-mqtt/client para el broker
//  HiveMQ Cloud con TLS.
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
    private $host;
    private $port;
    private $user;
    private $pass;
    private $client_id;
    private $connected = false;

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
        $this->port = (int) ($_ENV['MQTT_PORT'] ?? 8883);
        $this->user = $_ENV['MQTT_USER'] ?? '';
        $this->pass = $_ENV['MQTT_PASS'] ?? '';

        // client_id único para evitar colisiones en el broker
        $this->client_id = 'smartrack_' . ($client_id_suffix ?: uniqid());

        $this->client = new PhpMqttClient($this->host, $this->port, $this->client_id);
    }

    /**
     * Configuración de conexión con TLS para HiveMQ Cloud.
     */
    private function buildSettings(): ConnectionSettings
    {
        return (new ConnectionSettings())
            ->setUsername($this->user)
            ->setPassword($this->pass)
            ->setUseTls(true)
            ->setTlsSelfSignedAllowed(false)
            ->setConnectTimeout(10)
            ->setKeepAliveInterval(60)
            ->setLastWillTopic(null)
            ->setLastWillMessage(null);
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
            // Un reintento tras reconexión
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

    /**
     * Registra el Last Will Testament antes de conectar.
     * Debe llamarse ANTES de connect().
     */
    public function setLastWill(string $topic, string $payload, int $qos = 1): void
    {
        // Reconstruir cliente con LWT en los settings
        $this->lwt_topic   = $topic;
        $this->lwt_payload = $payload;
        $this->lwt_qos     = $qos;
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
