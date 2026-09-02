<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Config;

use Etobi\Mensamax\Domain\DayKind;

final readonly class AccountConfig
{
    /**
     * @param list<int> $requiredWeekdays ISO weekdays (1 = Monday) on which an order is mandatory
     * @param list<int> $optionalWeekdays ISO weekdays on which an order is nice to have
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $project,
        public string $username,
        public string $password,
        public array $requiredWeekdays,
        public array $optionalWeekdays,
        public float $lowBalance,
        public int $reviewWindowDays,
        public int $lookaheadWeeks,
    ) {
        if (!preg_match('/^[a-z0-9_]+$/', $id)) {
            throw new ConfigException(sprintf('Account id "%s" must only contain a-z, 0-9 and underscore.', $id));
        }
    }

    public function dayKind(int $isoWeekday): DayKind
    {
        if (in_array($isoWeekday, $this->requiredWeekdays, true)) {
            return DayKind::Required;
        }
        if (in_array($isoWeekday, $this->optionalWeekdays, true)) {
            return DayKind::Optional;
        }

        return DayKind::None;
    }
}
