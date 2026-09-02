<?php

declare(strict_types=1);

namespace Etobi\Mensamax\HomeAssistant;

use Etobi\Mensamax\Config\AccountConfig;
use Etobi\Mensamax\Config\MqttConfig;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Contracts\MqttClient as MqttClientContract;
use PhpMqtt\Client\Exceptions\MqttClientException;
use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerInterface;

/**
 * Publishes discovery configs, availability and state for accounts. Retained, QoS 1.
 */
final class MqttPublisher
{
    private ?MqttClientContract $client = null;

    public function __construct(
        private readonly MqttConfig $config,
        private readonly EntityCatalog $catalog,
        private readonly LoggerInterface $logger,
        ?MqttClientContract $client = null,
    ) {
        $this->client = $client;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function publishAccount(AccountConfig $account, array $state): void
    {
        $client = $this->connect();
        foreach ($this->catalog->discoveryMessages($account) as $topic => $payload) {
            $client->publish($topic, self::json($payload), 1, true);
        }
        $client->publish($this->catalog->stateTopic($account), self::json($state), 1, true);
        $client->publish($this->catalog->availabilityTopic($account), 'online', 1, true);
        $this->logger->info(sprintf('Published state for account "%s" to %s', $account->id, $this->catalog->stateTopic($account)));
    }

    public function publishUnavailable(AccountConfig $account): void
    {
        $this->connect()->publish($this->catalog->availabilityTopic($account), 'offline', 1, true);
    }

    /**
     * Removes the discovery configs so Home Assistant deletes the entities and the device.
     */
    public function removeAccount(AccountConfig $account): void
    {
        $client = $this->connect();
        foreach (array_keys($this->catalog->discoveryMessages($account)) as $topic) {
            $client->publish($topic, '', 1, true);
        }
        $client->publish($this->catalog->stateTopic($account), '', 1, true);
        $client->publish($this->catalog->availabilityTopic($account), '', 1, true);
    }

    public function disconnect(): void
    {
        if ($this->client === null) {
            return;
        }
        try {
            $this->client->disconnect();
        } catch (MqttClientException $e) {
            $this->logger->warning('MQTT disconnect failed: ' . $e->getMessage());
        }
    }

    private function connect(): MqttClientContract
    {
        if ($this->client !== null && $this->client->isConnected()) {
            return $this->client;
        }

        $this->client ??= new MqttClient($this->config->host, $this->config->port, $this->config->clientId . '-' . bin2hex(random_bytes(3)));
        $settings = (new ConnectionSettings())
            ->setUsername($this->config->username)
            ->setPassword($this->config->password)
            ->setUseTls($this->config->tls)
            ->setConnectTimeout(10)
            ->setKeepAliveInterval(30);

        try {
            $this->client->connect($settings, true);
        } catch (MqttClientException $e) {
            throw new PublishException(sprintf('Cannot connect to MQTT broker %s:%d: %s', $this->config->host, $this->config->port, $e->getMessage()), 0, $e);
        }

        return $this->client;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
