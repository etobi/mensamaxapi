<?php

declare(strict_types=1);

namespace Etobi\Mensamax\HomeAssistant;

use Etobi\Mensamax\Config\AccountConfig;
use Etobi\Mensamax\Config\MqttConfig;

/**
 * Defines which Home Assistant entities exist per account and builds their MQTT discovery payloads.
 *
 * All entities read from one retained JSON state topic per account; every entity picks its
 * state with a value_template and its attributes with a json_attributes_template.
 */
final readonly class EntityCatalog
{
    /** Sensors go unavailable when no update arrived for this long (seconds). */
    private const int EXPIRE_AFTER = 26 * 3600;

    public function __construct(
        private MqttConfig $mqtt,
    ) {
    }

    public function stateTopic(AccountConfig $account): string
    {
        return sprintf('%s/%s/state', $this->mqtt->topicPrefix, $account->id);
    }

    public function availabilityTopic(AccountConfig $account): string
    {
        return sprintf('%s/%s/availability', $this->mqtt->topicPrefix, $account->id);
    }

    /**
     * @return array<string, array<string, mixed>> discovery topic => payload
     */
    public function discoveryMessages(AccountConfig $account): array
    {
        $messages = [];
        foreach ($this->entities() as $entity) {
            $component = $entity['component'];
            $objectId = sprintf('mensamax_%s_%s', $account->id, $entity['key']);
            $topic = sprintf('%s/%s/%s/config', $this->mqtt->discoveryPrefix, $component, $objectId);

            $payload = [
                'name' => $entity['name'],
                'unique_id' => $objectId,
                'default_entity_id' => $component . '.' . $objectId,
                'has_entity_name' => true,
                'state_topic' => $this->stateTopic($account),
                'value_template' => $entity['value_template'],
                'availability_topic' => $this->availabilityTopic($account),
                'payload_available' => 'online',
                'payload_not_available' => 'offline',
                'device' => $this->device($account),
                'origin' => ['name' => 'mensamax-api', 'url' => 'https://github.com/etobi/mensamax-api'],
            ];
            if (isset($entity['attributes'])) {
                $payload['json_attributes_topic'] = $this->stateTopic($account);
                $payload['json_attributes_template'] = $entity['attributes'];
            }
            if ($component === 'sensor') {
                $payload['expire_after'] = self::EXPIRE_AFTER;
            }
            foreach (['device_class', 'state_class', 'unit_of_measurement', 'icon', 'entity_category', 'payload_on', 'payload_off', 'suggested_display_precision'] as $key) {
                if (isset($entity[$key])) {
                    $payload[$key] = $entity[$key];
                }
            }

            $messages[$topic] = $payload;
        }

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    private function device(AccountConfig $account): array
    {
        return [
            'identifiers' => ['mensamax_' . $account->id],
            'name' => 'Mensamax ' . $account->name,
            'manufacturer' => 'Mensamax',
            'model' => 'mensamax-api',
            'configuration_url' => 'https://m.mensamax.de/',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entities(): array
    {
        $bool = fn (string $expr) => sprintf('{{ "ON" if %s else "OFF" }}', $expr);
        $day = fn (string $path) => sprintf(
            "{{ value_json.%s.order.main_short if value_json.%s and value_json.%s.order else "
            . "('Keine Bestellung' if value_json.%s and value_json.%s.status == 'missing' else 'Kein Essen') }}",
            $path, $path, $path, $path, $path,
        );

        return [
            [
                'component' => 'sensor', 'key' => 'kontostand', 'name' => 'Kontostand',
                'value_template' => '{{ value_json.balance.current }}',
                'attributes' => '{{ value_json.balance | tojson }}',
                'device_class' => 'monetary', 'state_class' => 'total', 'unit_of_measurement' => '€',
                'suggested_display_precision' => 2, 'icon' => 'mdi:wallet',
            ],
            [
                'component' => 'sensor', 'key' => 'bestellsumme', 'name' => 'Bestellsumme',
                'value_template' => '{{ value_json.balance.orders_sum }}',
                'attributes' => '{{ {"orders_count": value_json.balance.orders_count, "horizon_until": value_json.meta.horizon_until} | tojson }}',
                'device_class' => 'monetary', 'state_class' => 'total', 'unit_of_measurement' => '€',
                'suggested_display_precision' => 2, 'icon' => 'mdi:food',
            ],
            [
                'component' => 'sensor', 'key' => 'restbetrag', 'name' => 'Restbetrag',
                'value_template' => '{{ value_json.balance.remaining }}',
                'attributes' => '{{ {"covered_until": value_json.balance.covered_until, "uncovered_from": value_json.balance.uncovered_from, "mensamax_future": value_json.balance.mensamax_future} | tojson }}',
                'device_class' => 'monetary', 'state_class' => 'total', 'unit_of_measurement' => '€',
                'suggested_display_precision' => 2, 'icon' => 'mdi:cash-clock',
            ],
            [
                'component' => 'binary_sensor', 'key' => 'guthaben_niedrig', 'name' => 'Guthaben niedrig',
                'value_template' => $bool('value_json.balance.low'),
                'attributes' => '{{ {"current": value_json.balance.current, "threshold": value_json.balance.threshold} | tojson }}',
                'device_class' => 'problem',
            ],
            [
                'component' => 'sensor', 'key' => 'heute', 'name' => 'Heute',
                'value_template' => $day('today'),
                'attributes' => '{{ value_json.today | tojson }}',
                'icon' => 'mdi:silverware-fork-knife',
            ],
            [
                'component' => 'sensor', 'key' => 'morgen', 'name' => 'Morgen',
                'value_template' => $day('tomorrow'),
                'attributes' => '{{ value_json.tomorrow | tojson }}',
                'icon' => 'mdi:silverware-fork-knife',
            ],
            [
                'component' => 'sensor', 'key' => 'naechste_bestellung', 'name' => 'Nächste Bestellung',
                'value_template' => "{{ value_json.next_order.order.main_short if value_json.next_order else 'Keine' }}",
                'attributes' => '{{ (value_json.next_order or {}) | tojson }}',
                'icon' => 'mdi:calendar-arrow-right',
            ],
            [
                'component' => 'sensor', 'key' => 'diese_woche', 'name' => 'Diese Woche',
                'value_template' => '{{ value_json.weeks.this.ordered_count }}',
                'attributes' => '{{ value_json.weeks.this | tojson }}',
                'icon' => 'mdi:calendar-week', 'unit_of_measurement' => 'Tage',
            ],
            [
                'component' => 'sensor', 'key' => 'naechste_woche', 'name' => 'Nächste Woche',
                'value_template' => '{{ value_json.weeks.next.ordered_count }}',
                'attributes' => '{{ value_json.weeks.next | tojson }}',
                'icon' => 'mdi:calendar-week', 'unit_of_measurement' => 'Tage',
            ],
            [
                'component' => 'sensor', 'key' => 'in_zwei_wochen', 'name' => 'In zwei Wochen',
                'value_template' => '{{ value_json.weeks.in_two.ordered_count }}',
                'attributes' => '{{ value_json.weeks.in_two | tojson }}',
                'icon' => 'mdi:calendar-week', 'unit_of_measurement' => 'Tage',
            ],
            [
                'component' => 'binary_sensor', 'key' => 'bestellung_fehlt', 'name' => 'Bestellung fehlt',
                'value_template' => $bool('value_json.alerts.order_missing'),
                'attributes' => '{{ value_json.alerts | tojson }}',
                'device_class' => 'problem',
            ],
            [
                'component' => 'sensor', 'key' => 'pruefung', 'name' => 'Menüprüfung',
                'value_template' => "{{ value_json.review.order_deadline if value_json.review else None }}",
                'attributes' => '{{ (value_json.review or {}) | tojson }}',
                'device_class' => 'timestamp', 'icon' => 'mdi:clipboard-check-outline',
            ],
            [
                'component' => 'binary_sensor', 'key' => 'pruefung_faellig', 'name' => 'Menüprüfung fällig',
                'value_template' => $bool('value_json.review and value_json.review.due'),
                'attributes' => '{{ {"week": (value_json.review.label if value_json.review else None), "order_deadline": (value_json.review.order_deadline if value_json.review else None), "summary": (value_json.review.summary if value_json.review else None)} | tojson }}',
                'icon' => 'mdi:clipboard-alert-outline',
            ],
            [
                'component' => 'sensor', 'key' => 'letzte_aktualisierung', 'name' => 'Letzte Aktualisierung',
                'value_template' => '{{ value_json.meta.fetched_at }}',
                'attributes' => '{{ value_json.meta | tojson }}',
                'device_class' => 'timestamp', 'entity_category' => 'diagnostic',
            ],
        ];
    }
}
