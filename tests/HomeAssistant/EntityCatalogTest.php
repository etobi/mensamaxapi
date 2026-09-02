<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Tests\HomeAssistant;

use Etobi\Mensamax\Config\MqttConfig;
use Etobi\Mensamax\HomeAssistant\EntityCatalog;
use Etobi\Mensamax\Tests\FixtureHelper;
use PHPUnit\Framework\TestCase;

final class EntityCatalogTest extends TestCase
{
    private function catalog(): EntityCatalog
    {
        return new EntityCatalog(new MqttConfig('h', 1883, null, null, false, 'homeassistant', 'mensamax', 'test'));
    }

    public function testTopics(): void
    {
        $account = FixtureHelper::account();

        self::assertSame('mensamax/anna/state', $this->catalog()->stateTopic($account));
        self::assertSame('mensamax/anna/availability', $this->catalog()->availabilityTopic($account));
    }

    public function testDiscoveryMessagesAreUniqueAndComplete(): void
    {
        $messages = $this->catalog()->discoveryMessages(FixtureHelper::account());

        self::assertArrayHasKey('homeassistant/sensor/mensamax_anna_kontostand/config', $messages);
        self::assertArrayHasKey('homeassistant/binary_sensor/mensamax_anna_bestellung_fehlt/config', $messages);
        self::assertArrayHasKey('homeassistant/binary_sensor/mensamax_anna_pruefung_faellig/config', $messages);

        $uniqueIds = array_map(fn (array $p) => $p['unique_id'], $messages);
        self::assertSame(array_unique($uniqueIds), $uniqueIds);

        foreach ($messages as $topic => $payload) {
            self::assertSame('mensamax/anna/state', $payload['state_topic'], $topic);
            self::assertSame('mensamax/anna/availability', $payload['availability_topic'], $topic);
            self::assertSame(['mensamax_anna'], $payload['device']['identifiers'], $topic);
            self::assertSame('Mensamax Anna', $payload['device']['name'], $topic);
            self::assertNotEmpty($payload['value_template'], $topic);
            if (str_starts_with($topic, 'homeassistant/sensor/')) {
                self::assertSame(26 * 3600, $payload['expire_after'], $topic);
            }
            if (str_starts_with($topic, 'homeassistant/binary_sensor/')) {
                self::assertArrayNotHasKey('expire_after', $payload, $topic);
            }
        }

        $balance = $messages['homeassistant/sensor/mensamax_anna_kontostand/config'];
        self::assertSame('sensor.mensamax_anna_kontostand', $balance['default_entity_id']);
        self::assertArrayNotHasKey('object_id', $balance, 'object_id was removed from MQTT discovery, default_entity_id replaces it');
        self::assertSame('binary_sensor.mensamax_anna_pruefung_faellig', $messages['homeassistant/binary_sensor/mensamax_anna_pruefung_faellig/config']['default_entity_id']);
        self::assertSame('monetary', $balance['device_class']);
        self::assertSame('€', $balance['unit_of_measurement']);
    }
}
