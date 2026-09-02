<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Tests\Config;

use Etobi\Mensamax\Config\AppConfig;
use Etobi\Mensamax\Config\ConfigException;
use Etobi\Mensamax\Domain\DayKind;
use PHPUnit\Framework\TestCase;

final class AppConfigTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function baseEnv(): array
    {
        return [
            'MENSAMAX_ACCOUNTS' => 'anna, ben',
            'MENSAMAX_PROJECT' => 'SCHOOL1',
            'MENSAMAX_ANNA_USERNAME' => 'anna-login',
            'MENSAMAX_ANNA_PASSWORD' => 'pw-a',
            'MENSAMAX_BEN_NAME' => 'Benjamin',
            'MENSAMAX_BEN_PROJECT' => 'SCHOOL2',
            'MENSAMAX_BEN_USERNAME' => 'ben-login',
            'MENSAMAX_BEN_PASSWORD' => 'pw-b',
            'MENSAMAX_BEN_REQUIRED_WEEKDAYS' => '1,2,3,4,5',
            'MENSAMAX_BEN_OPTIONAL_WEEKDAYS' => '',
            'MENSAMAX_LOW_BALANCE' => '25',
            'MQTT_HOST' => 'mqtt.local',
            'MQTT_USERNAME' => 'ha',
            'MQTT_PASSWORD' => 'secret',
            'LLM_PROVIDER' => 'claude',
            'LLM_API_KEY' => 'key',
        ];
    }

    public function testParsesAccountsWithDefaultsAndOverrides(): void
    {
        $config = AppConfig::fromEnv($this->baseEnv());

        self::assertCount(2, $config->accounts);
        [$anna, $ben] = $config->accounts;

        self::assertSame('anna', $anna->id);
        self::assertSame('Anna', $anna->name);
        self::assertSame('SCHOOL1', $anna->project);
        self::assertSame([2, 3, 4], $anna->requiredWeekdays);
        self::assertSame([1], $anna->optionalWeekdays);
        self::assertSame(25.0, $anna->lowBalance);
        self::assertSame(DayKind::Optional, $anna->dayKind(1));
        self::assertSame(DayKind::Required, $anna->dayKind(3));
        self::assertSame(DayKind::None, $anna->dayKind(5));

        self::assertSame('Benjamin', $ben->name);
        self::assertSame('SCHOOL2', $ben->project);
        self::assertSame([1, 2, 3, 4, 5], $ben->requiredWeekdays);
        self::assertSame([1], $ben->optionalWeekdays, 'empty value falls back to the default');

        self::assertSame('https://m.mensamax.de', $config->mensamaxBaseUrl);
        self::assertSame('mqtt.local', $config->mqtt->host);
        self::assertSame(1883, $config->mqtt->port);
        self::assertSame('homeassistant', $config->mqtt->discoveryPrefix);
        self::assertSame('claude', $config->llm->provider);
        self::assertSame('Europe/Berlin', $config->timezone);
    }

    public function testSurroundingQuotesAreStripped(): void
    {
        $env = $this->baseEnv();
        $env['MENSAMAX_ANNA_NAME'] = '"Anna Lena"';
        $env['MQTT_PASSWORD'] = "'se cret'";

        $config = AppConfig::fromEnv($env);

        self::assertSame('Anna Lena', $config->accounts[0]->name);
        self::assertSame('se cret', $config->mqtt->password);
    }

    public function testMissingCredentialsFail(): void
    {
        $env = $this->baseEnv();
        unset($env['MENSAMAX_ANNA_PASSWORD']);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('MENSAMAX_ANNA_PASSWORD');
        AppConfig::fromEnv($env);
    }

    public function testMissingAccountsFail(): void
    {
        $env = $this->baseEnv();
        $env['MENSAMAX_ACCOUNTS'] = '';

        $this->expectException(ConfigException::class);
        AppConfig::fromEnv($env);
    }

    public function testInvalidWeekdayFails(): void
    {
        $env = $this->baseEnv();
        $env['MENSAMAX_REQUIRED_WEEKDAYS'] = '2,8';

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('MENSAMAX_ANNA_REQUIRED_WEEKDAYS');
        AppConfig::fromEnv($env);
    }

    public function testUnknownLlmProviderFails(): void
    {
        $env = $this->baseEnv();
        $env['LLM_PROVIDER'] = 'gemini';

        $this->expectException(ConfigException::class);
        AppConfig::fromEnv($env);
    }
}
