<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Etobi\Mensamax\Config\AccountConfig;
use Etobi\Mensamax\Domain\AccountSnapshot;
use Etobi\Mensamax\Mensamax\SnapshotFetcher;

final class FixtureHelper
{
    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone('Europe/Berlin');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rawMenuPlan(): array
    {
        $json = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/meinSpeiseplan.json'), true, 512, JSON_THROW_ON_ERROR);

        return $json['data']['meinSpeiseplan'];
    }

    /**
     * Fixture covers Mon 2026-08-31 .. Sun 2026-09-20 (3 weeks). Orders on Tue/Wed/Thu (menu 2 on
     * some days, menu 1 on others), Mondays 07.09. and 14.09. without order, Fridays hidden.
     */
    public static function snapshot(float $balance = 89.56, ?float $future = -390.87, ?DateTimeImmutable $fetchedAt = null): AccountSnapshot
    {
        return new AccountSnapshot(
            balanceCurrent: $balance,
            balanceFuture: $future,
            days: SnapshotFetcher::parseDays(self::rawMenuPlan(), self::timezone()),
            fetchedAt: $fetchedAt ?? self::now(),
        );
    }

    public static function now(string $time = '2026-09-02 10:00:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($time, self::timezone());
    }

    public static function account(array $overrides = []): AccountConfig
    {
        return new AccountConfig(...array_merge([
            'id' => 'anna',
            'name' => 'Anna',
            'project' => 'PRJ',
            'username' => 'user',
            'password' => 'secret',
            'requiredWeekdays' => [2, 3, 4],
            'optionalWeekdays' => [1],
            'lowBalance' => 20.0,
            'reviewWindowDays' => 3,
            'lookaheadWeeks' => 3,
        ], $overrides));
    }
}
