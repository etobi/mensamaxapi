<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Domain;

enum DayStatus: string
{
    /** A menu is ordered for this day. */
    case Ordered = 'ordered';
    /** Nothing ordered although the day is required or optional and menus are offered. */
    case Missing = 'missing';
    /** Menus are offered, but the weekday is neither required nor optional. */
    case NotNeeded = 'not_needed';
    /** No menu offered: weekend, holiday, blocked day. */
    case NotOffered = 'not_offered';
}
