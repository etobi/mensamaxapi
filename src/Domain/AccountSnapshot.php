<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Domain;

use DateTimeImmutable;

/**
 * Everything fetched from Mensamax for one account at one point in time.
 */
final readonly class AccountSnapshot
{
    /**
     * @param array<string, DayPlan> $days keyed by ISO date, sorted ascending
     */
    public function __construct(
        public float $balanceCurrent,
        public ?float $balanceFuture,
        public array $days,
        public DateTimeImmutable $fetchedAt,
    ) {
    }

    public function day(DateTimeImmutable $date): ?DayPlan
    {
        return $this->days[$date->format('Y-m-d')] ?? null;
    }
}
