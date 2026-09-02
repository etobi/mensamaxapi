<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Domain;

/**
 * How important an order is on a given weekday, according to the account configuration.
 */
enum DayKind: string
{
    case Required = 'required';
    case Optional = 'optional';
    case None = 'none';
}
