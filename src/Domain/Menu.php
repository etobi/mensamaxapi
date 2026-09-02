<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Domain;

/**
 * One menu ("Gericht") offered on a day.
 */
final readonly class Menu
{
    public function __construct(
        public int $id,
        public int $number,
        public string $group,
        public string $starter,
        public string $main,
        public string $dessert,
        public bool $vegetarian,
        public ?float $price,
        public bool $visible,
        public int $orderedQuantity,
    ) {
    }

    public function isOrdered(): bool
    {
        return $this->orderedQuantity > 0;
    }

    /** Full text of the whole menu, courses separated by comma. */
    public function fullText(): string
    {
        return implode(', ', array_filter([$this->starter, $this->main, $this->dessert], fn (string $s) => $s !== ''));
    }
}
