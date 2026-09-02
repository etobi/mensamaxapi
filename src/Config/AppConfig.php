<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Config;

/**
 * Reads the whole configuration from environment variables.
 *
 * Accounts are listed in MENSAMAX_ACCOUNTS (comma separated ids). Every account reads
 * MENSAMAX_<ID>_<KEY>; keys that are not set per account fall back to MENSAMAX_<KEY>.
 */
final readonly class AppConfig
{
    /**
     * @param list<AccountConfig> $accounts
     */
    public function __construct(
        public string $mensamaxBaseUrl,
        public array $accounts,
        public MqttConfig $mqtt,
        public LlmConfig $llm,
        public string $dataDir,
        public string $timezone,
    ) {
    }

    /**
     * @param array<string, mixed> $env
     */
    public static function fromEnv(array $env): self
    {
        // "docker run --env-file" keeps surrounding quotes, docker compose and Dotenv strip them: accept both.
        $get = static function (string $key, ?string $default = null) use ($env): ?string {
            if (!isset($env[$key]) || !is_scalar($env[$key])) {
                return $default;
            }
            $value = trim((string) $env[$key]);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            return $value !== '' ? $value : $default;
        };

        $accountIds = array_values(array_filter(array_map('trim', explode(',', $get('MENSAMAX_ACCOUNTS', '') ?? ''))));
        if ($accountIds === []) {
            throw new ConfigException('MENSAMAX_ACCOUNTS must list at least one account id, e.g. MENSAMAX_ACCOUNTS=anna,ben');
        }

        $accounts = [];
        foreach ($accountIds as $id) {
            $prefix = 'MENSAMAX_' . strtoupper($id) . '_';
            $acc = static fn (string $key, ?string $default = null): ?string => $get($prefix . $key) ?? $get('MENSAMAX_' . $key, $default);
            $require = static function (string $key) use ($acc, $prefix): string {
                $value = $acc($key);
                if ($value === null) {
                    throw new ConfigException(sprintf('Missing %s%s (or MENSAMAX_%s as default)', $prefix, $key, $key));
                }

                return $value;
            };

            $accounts[] = new AccountConfig(
                id: strtolower($id),
                name: $acc('NAME', ucfirst($id)),
                project: $require('PROJECT'),
                username: $require('USERNAME'),
                password: $require('PASSWORD'),
                requiredWeekdays: self::parseWeekdays($acc('REQUIRED_WEEKDAYS', '2,3,4'), $prefix . 'REQUIRED_WEEKDAYS'),
                optionalWeekdays: self::parseWeekdays($acc('OPTIONAL_WEEKDAYS', '1'), $prefix . 'OPTIONAL_WEEKDAYS'),
                lowBalance: (float) $acc('LOW_BALANCE', '20'),
                reviewWindowDays: (int) $acc('REVIEW_WINDOW_DAYS', '3'),
                lookaheadWeeks: max(3, (int) $acc('LOOKAHEAD_WEEKS', '4')),
            );
        }

        $provider = strtolower($get('LLM_PROVIDER', 'none') ?? 'none');
        if (!in_array($provider, ['none', 'claude', 'openai'], true)) {
            throw new ConfigException(sprintf('LLM_PROVIDER must be one of none, claude, openai; got "%s"', $provider));
        }

        return new self(
            mensamaxBaseUrl: rtrim($get('MENSAMAX_BASE_URL', 'https://m.mensamax.de'), '/'),
            accounts: $accounts,
            mqtt: new MqttConfig(
                host: $get('MQTT_HOST') ?? throw new ConfigException('MQTT_HOST is required'),
                port: (int) $get('MQTT_PORT', '1883'),
                username: $get('MQTT_USERNAME'),
                password: $get('MQTT_PASSWORD'),
                tls: filter_var($get('MQTT_TLS', 'false'), FILTER_VALIDATE_BOOL),
                discoveryPrefix: trim($get('MQTT_DISCOVERY_PREFIX', 'homeassistant'), '/'),
                topicPrefix: trim($get('MQTT_TOPIC_PREFIX', 'mensamax'), '/'),
                clientId: $get('MQTT_CLIENT_ID', 'mensamax-ha'),
            ),
            llm: new LlmConfig(
                provider: $provider,
                model: $get('LLM_MODEL', '') ?? '',
                apiKey: $get('LLM_API_KEY', '') ?? '',
                maxLength: (int) $get('LLM_SHORT_MAX_LENGTH', '30'),
            ),
            dataDir: rtrim($get('DATA_DIR', dirname(__DIR__, 2) . '/data'), '/'),
            timezone: $get('TZ', 'Europe/Berlin'),
        );
    }

    /**
     * @return list<int>
     */
    private static function parseWeekdays(string $value, string $key): array
    {
        $days = [];
        foreach (array_filter(array_map('trim', explode(',', $value))) as $day) {
            if (!ctype_digit($day) || (int) $day < 1 || (int) $day > 7) {
                throw new ConfigException(sprintf('%s must contain ISO weekdays 1-7 (1 = Monday), got "%s"', $key, $value));
            }
            $days[] = (int) $day;
        }

        return array_values(array_unique($days));
    }
}
