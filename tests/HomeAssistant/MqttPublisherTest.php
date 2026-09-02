<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Tests\HomeAssistant;

use Etobi\Mensamax\Config\MqttConfig;
use Etobi\Mensamax\HomeAssistant\EntityCatalog;
use Etobi\Mensamax\HomeAssistant\MqttPublisher;
use Etobi\Mensamax\Tests\FixtureHelper;
use PhpMqtt\Client\Contracts\MqttClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MqttPublisherTest extends TestCase
{
    public function testPublishesDiscoveryStateAndAvailabilityRetained(): void
    {
        $published = [];
        $client = $this->createStub(MqttClient::class);
        $client->method('isConnected')->willReturn(true);
        $client->method('publish')->willReturnCallback(function (string $topic, string $message, int $qos, bool $retain) use (&$published): void {
            $published[$topic] = [$message, $qos, $retain];
        });

        $mqtt = new MqttConfig('h', 1883, null, null, false, 'homeassistant', 'mensamax', 'test');
        $publisher = new MqttPublisher($mqtt, new EntityCatalog($mqtt), new NullLogger(), $client);
        $publisher->publishAccount(FixtureHelper::account(), ['balance' => ['current' => 1.5]]);

        self::assertSame(['{"balance":{"current":1.5}}', 1, true], $published['mensamax/anna/state']);
        self::assertSame(['online', 1, true], $published['mensamax/anna/availability']);
        self::assertArrayHasKey('homeassistant/sensor/mensamax_anna_kontostand/config', $published);
        $config = json_decode($published['homeassistant/sensor/mensamax_anna_kontostand/config'][0], true);
        self::assertSame('mensamax_anna_kontostand', $config['unique_id']);
        foreach ($published as $topic => [, $qos, $retain]) {
            self::assertTrue($retain, $topic);
        }
    }

    public function testUnavailableAndRemove(): void
    {
        $published = [];
        $client = $this->createStub(MqttClient::class);
        $client->method('isConnected')->willReturn(true);
        $client->method('publish')->willReturnCallback(function (string $topic, string $message) use (&$published): void {
            $published[$topic] = $message;
        });

        $mqtt = new MqttConfig('h', 1883, null, null, false, 'homeassistant', 'mensamax', 'test');
        $publisher = new MqttPublisher($mqtt, new EntityCatalog($mqtt), new NullLogger(), $client);

        $publisher->publishUnavailable(FixtureHelper::account());
        self::assertSame('offline', $published['mensamax/anna/availability']);

        $publisher->removeAccount(FixtureHelper::account());
        self::assertSame('', $published['mensamax/anna/state']);
        self::assertSame('', $published['homeassistant/sensor/mensamax_anna_kontostand/config']);
    }
}
