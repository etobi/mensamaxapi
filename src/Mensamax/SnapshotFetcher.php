<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Mensamax;

use DateTimeImmutable;
use DateTimeZone;
use Etobi\Mensamax\Config\AccountConfig;
use Etobi\Mensamax\Domain\AccountSnapshot;
use Etobi\Mensamax\Domain\DayPlan;
use Etobi\Mensamax\Domain\Menu;

/**
 * Logs in for one account and turns the raw Mensamax answers into an AccountSnapshot.
 */
final readonly class SnapshotFetcher
{
    public function __construct(
        private MensamaxClient $client,
        private DateTimeZone $timezone,
    ) {
    }

    public function fetch(AccountConfig $account, DateTimeImmutable $now): AccountSnapshot
    {
        $this->client->login($account->project, $account->username, $account->password);
        $personId = $this->client->personId();
        $balance = $this->client->balance();

        $from = $now->setTimezone($this->timezone)->modify('monday this week')->setTime(0, 0);
        $to = $from->modify(sprintf('+%d days', $account->lookaheadWeeks * 7 - 1))->setTime(23, 59, 59);

        $days = self::parseDays($this->client->menuPlan($from, $to, $personId), $this->timezone);

        return new AccountSnapshot(
            balanceCurrent: $balance['current'],
            balanceFuture: $balance['future'],
            days: $days,
            fetchedAt: $now,
        );
    }

    /**
     * @param list<array<string, mixed>> $rawDays
     * @return array<string, DayPlan>
     */
    public static function parseDays(array $rawDays, DateTimeZone $timezone): array
    {
        $days = [];
        foreach ($rawDays as $raw) {
            if (empty($raw['datum'])) {
                continue;
            }
            $date = new DateTimeImmutable((string) $raw['datum']);
            $date = $date->setTimezone($timezone)->setTime(0, 0);

            $menus = [];
            $orderDeadline = null;
            $cancelDeadline = null;
            foreach ($raw['menues'] ?? [] as $index => $rawMenu) {
                $menus[] = new Menu(
                    id: (int) ($rawMenu['id'] ?? 0),
                    number: (int) ($rawMenu['reihenfolge'] ?? $index + 1),
                    group: trim((string) ($rawMenu['menuegruppe']['bezeichnung'] ?? '')),
                    starter: self::courseText($rawMenu['vorspeisen'] ?? []),
                    main: self::courseText($rawMenu['hauptspeisen'] ?? []),
                    dessert: self::courseText($rawMenu['nachspeisen'] ?? []),
                    vegetarian: (bool) ($rawMenu['hauptspeisen'][0]['vegetarisch'] ?? false),
                    price: isset($rawMenu['preis']) ? (float) $rawMenu['preis'] : null,
                    visible: (bool) ($rawMenu['meinSpeiseplanShow'] ?? true),
                    orderedQuantity: (int) ($rawMenu['meineBestellung']['anzahl'] ?? 0),
                );
                $orderDeadline ??= self::parseDate($rawMenu['fristen']['bestellungBis'] ?? null, $timezone);
                $cancelDeadline ??= self::parseDate($rawMenu['fristen']['abbestellungBis'] ?? null, $timezone);
            }
            usort($menus, fn (Menu $a, Menu $b) => $a->number <=> $b->number);

            $message = isset($raw['message']) ? trim((string) $raw['message']) : '';
            $days[$date->format('Y-m-d')] = new DayPlan(
                date: $date,
                message: $message !== '' ? $message : null,
                menus: $menus,
                orderDeadline: $orderDeadline,
                cancelDeadline: $cancelDeadline,
            );
        }
        ksort($days);

        return $days;
    }

    /**
     * @param list<array<string, mixed>> $courses
     */
    private static function courseText(array $courses): string
    {
        $parts = [];
        foreach ($courses as $course) {
            $text = trim(preg_replace('/\s+/', ' ', trim((string) ($course['bezeichnung'] ?? '')) . ' ' . trim((string) ($course['beschreibung'] ?? ''))) ?? '');
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode(', ', $parts);
    }

    private static function parseDate(mixed $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone($timezone);
        } catch (\Exception) {
            return null;
        }
    }
}
