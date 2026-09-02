<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Domain;

use DateTimeImmutable;

/**
 * The menu plan of one calendar day as Mensamax reports it, including the account's order.
 */
final readonly class DayPlan
{
    /**
     * @param list<Menu> $menus
     */
    public function __construct(
        public DateTimeImmutable $date,
        public ?string $message,
        public array $menus,
        public ?DateTimeImmutable $orderDeadline,
        public ?DateTimeImmutable $cancelDeadline,
    ) {
    }

    public function isoDate(): string
    {
        return $this->date->format('Y-m-d');
    }

    /** ISO weekday, 1 = Monday ... 7 = Sunday. */
    public function weekday(): int
    {
        return (int) $this->date->format('N');
    }

    /** @return list<Menu> */
    public function offeredMenus(): array
    {
        return array_values(array_filter($this->menus, fn (Menu $m) => $m->visible));
    }

    public function isOffered(): bool
    {
        return $this->offeredMenus() !== [];
    }

    public function orderedMenu(): ?Menu
    {
        foreach ($this->menus as $menu) {
            if ($menu->isOrdered()) {
                return $menu;
            }
        }

        return null;
    }

    public function isEditable(DateTimeImmutable $now): bool
    {
        return $this->orderDeadline !== null && $now < $this->orderDeadline;
    }
}
