<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Overview;

use DateTimeImmutable;
use Etobi\Mensamax\Config\AccountConfig;
use Etobi\Mensamax\Domain\AccountSnapshot;
use Etobi\Mensamax\Domain\DayKind;
use Etobi\Mensamax\Domain\DayPlan;
use Etobi\Mensamax\Domain\DayStatus;
use Etobi\Mensamax\Domain\Menu;

/**
 * Pure function: snapshot + account config + "now" -> the JSON structure Home Assistant gets.
 *
 * Everything time dependent (today, this week, deadlines) is computed here so that the
 * result is testable with a fixed clock.
 */
final readonly class OverviewBuilder
{
    private const array WEEKDAYS = [1 => 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

    /**
     * @param array<string, string> $shortTexts full main dish text => short text
     */
    public function __construct(
        private array $shortTexts = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(AccountSnapshot $snapshot, AccountConfig $account, DateTimeImmutable $now, ?string $llmError = null): array
    {
        $today = $now->setTime(0, 0);
        $thisMonday = $today->modify('monday this week');

        $weeks = [];
        foreach (['this' => 0, 'next' => 1, 'in_two' => 2, 'in_three' => 3] as $key => $offset) {
            $monday = $thisMonday->modify(sprintf('+%d weeks', $offset));
            if ($offset >= $account->lookaheadWeeks) {
                continue;
            }
            $weeks[$key] = $this->weekView($snapshot, $account, $monday, $now);
        }

        $horizonEnd = $thisMonday->modify(sprintf('+%d days', $account->lookaheadWeeks * 7 - 1));
        $alerts = $this->alerts($snapshot, $account, $today, $now, $horizonEnd);

        return [
            'account' => ['id' => $account->id, 'name' => $account->name],
            'balance' => $this->balance($snapshot, $account, $today, $horizonEnd),
            'today' => $this->dayView($snapshot->day($today), $today, $account, $now),
            'tomorrow' => $this->dayView($snapshot->day($today->modify('+1 day')), $today->modify('+1 day'), $account, $now),
            'next_order' => $this->nextOrder($snapshot, $account, $today, $now),
            'weeks' => $weeks,
            'alerts' => $alerts,
            'review' => $this->review($snapshot, $account, $thisMonday, $now),
            'meta' => [
                'fetched_at' => $snapshot->fetchedAt->format(DATE_ATOM),
                'horizon_from' => $thisMonday->format('Y-m-d'),
                'horizon_until' => $horizonEnd->format('Y-m-d'),
                'llm_error' => $llmError,
                'required_weekdays' => $account->requiredWeekdays,
                'optional_weekdays' => $account->optionalWeekdays,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function balance(AccountSnapshot $snapshot, AccountConfig $account, DateTimeImmutable $today, DateTimeImmutable $horizonEnd): array
    {
        $sum = 0.0;
        $count = 0;
        $running = $snapshot->balanceCurrent;
        $coveredUntil = null;
        $uncoveredFrom = null;
        foreach ($snapshot->days as $day) {
            if ($day->date < $today || $day->date > $horizonEnd) {
                continue;
            }
            $order = $day->orderedMenu();
            if ($order === null) {
                continue;
            }
            $price = ($order->price ?? 0.0) * max(1, $order->orderedQuantity);
            $sum += $price;
            $count++;
            $running -= $price;
            if ($running >= 0) {
                $coveredUntil = $day->isoDate();
            } elseif ($uncoveredFrom === null) {
                $uncoveredFrom = $day->isoDate();
            }
        }

        return [
            'current' => round($snapshot->balanceCurrent, 2),
            'mensamax_future' => $snapshot->balanceFuture !== null ? round($snapshot->balanceFuture, 2) : null,
            'orders_sum' => round($sum, 2),
            'orders_count' => $count,
            'remaining' => round($snapshot->balanceCurrent - $sum, 2),
            'covered_until' => $coveredUntil,
            'uncovered_from' => $uncoveredFrom,
            'low' => $snapshot->balanceCurrent < $account->lowBalance,
            'threshold' => $account->lowBalance,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function weekView(AccountSnapshot $snapshot, AccountConfig $account, DateTimeImmutable $monday, DateTimeImmutable $now): array
    {
        $days = [];
        $orderedCount = 0;
        $missingRequired = [];
        $missingOptional = [];
        $deadline = null;
        $summary = [];
        for ($i = 0; $i < 5; $i++) {
            $date = $monday->modify(sprintf('+%d days', $i));
            $plan = $snapshot->day($date);
            $view = $this->dayView($plan, $date, $account, $now, withMenus: false);
            $days[] = $view;

            if ($view['status'] === DayStatus::Ordered->value) {
                $orderedCount++;
            } elseif ($view['status'] === DayStatus::Missing->value) {
                if ($view['kind'] === DayKind::Required->value) {
                    $missingRequired[] = $view['date'];
                } else {
                    $missingOptional[] = $view['date'];
                }
            }
            if ($plan?->orderDeadline !== null && $plan->isOffered() && ($deadline === null || $plan->orderDeadline < $deadline)) {
                $deadline = $plan->orderDeadline;
            }
            if ($view['status'] !== DayStatus::NotOffered->value || $view['message'] !== null) {
                $summary[] = $view['summary'];
            }
        }

        return [
            'monday' => $monday->format('Y-m-d'),
            'friday' => $monday->modify('+4 days')->format('Y-m-d'),
            'iso_week' => (int) $monday->format('W'),
            'year' => (int) $monday->format('o'),
            'label' => sprintf('KW %d (%s)', (int) $monday->format('W'), $monday->format('d.m.')),
            'ordered_count' => $orderedCount,
            'missing_required' => $missingRequired,
            'missing_optional' => $missingOptional,
            'complete' => $missingRequired === [],
            'order_deadline' => $deadline?->format(DATE_ATOM),
            'editable' => $deadline !== null && $now < $deadline,
            'summary' => implode("\n", $summary),
            'days' => $days,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dayView(?DayPlan $plan, DateTimeImmutable $date, AccountConfig $account, DateTimeImmutable $now, bool $withMenus = true): array
    {
        $weekday = (int) $date->format('N');
        $kind = $account->dayKind($weekday);
        $offered = $plan?->isOffered() ?? false;
        $order = $plan?->orderedMenu();

        if ($order !== null) {
            $status = DayStatus::Ordered;
        } elseif (!$offered) {
            $status = DayStatus::NotOffered;
        } elseif ($kind === DayKind::None) {
            $status = DayStatus::NotNeeded;
        } else {
            $status = DayStatus::Missing;
        }

        $label = sprintf('%s %s', self::WEEKDAYS[$weekday], $date->format('d.m.'));
        $summary = match ($status) {
            DayStatus::Ordered => sprintf('%s: Gericht %d, %s', $label, $order->number, $this->short($order->main)),
            DayStatus::Missing => sprintf('%s: keine Bestellung%s', $label, $kind === DayKind::Required ? ' (Pflichttag)' : ''),
            DayStatus::NotNeeded => sprintf('%s: nicht benötigt', $label),
            DayStatus::NotOffered => sprintf('%s: %s', $label, $plan?->message ?? 'kein Angebot'),
        };

        return [
            'date' => $date->format('Y-m-d'),
            'weekday' => $weekday,
            'label' => $label,
            'kind' => $kind->value,
            'status' => $status->value,
            'offered' => $offered,
            'message' => $plan?->message,
            'editable' => $plan?->isEditable($now) ?? false,
            'order_deadline' => $plan?->orderDeadline?->format(DATE_ATOM),
            'order' => $order !== null ? $this->menuView($order) : null,
            'summary' => $summary,
        ] + ($withMenus ? ['menus' => array_map(fn (Menu $m) => $this->menuView($m), $plan?->offeredMenus() ?? [])] : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function menuView(Menu $menu): array
    {
        return [
            'number' => $menu->number,
            'group' => $menu->group,
            'main' => $menu->main,
            'main_short' => $this->short($menu->main),
            'starter' => $menu->starter,
            'dessert' => $menu->dessert,
            'full_text' => $menu->fullText(),
            'price' => $menu->price,
            'vegetarian' => $menu->vegetarian,
            'ordered' => $menu->isOrdered(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nextOrder(AccountSnapshot $snapshot, AccountConfig $account, DateTimeImmutable $today, DateTimeImmutable $now): ?array
    {
        foreach ($snapshot->days as $day) {
            if ($day->date >= $today && $day->orderedMenu() !== null) {
                return $this->dayView($day, $day->date, $account, $now);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function alerts(AccountSnapshot $snapshot, AccountConfig $account, DateTimeImmutable $today, DateTimeImmutable $now, DateTimeImmutable $horizonEnd): array
    {
        $missingRequired = [];
        $missingOptional = [];
        $missedRequired = [];
        foreach ($snapshot->days as $day) {
            if ($day->date < $today || $day->date > $horizonEnd || !$day->isOffered() || $day->orderedMenu() !== null) {
                continue;
            }
            $kind = $account->dayKind($day->weekday());
            if ($kind === DayKind::Required) {
                if ($day->isEditable($now)) {
                    $missingRequired[] = $day->isoDate();
                } else {
                    $missedRequired[] = $day->isoDate();
                }
            } elseif ($kind === DayKind::Optional && $day->isEditable($now)) {
                $missingOptional[] = $day->isoDate();
            }
        }

        return [
            'order_missing' => $missingRequired !== [],
            'missing_required' => $missingRequired,
            'missing_optional' => $missingOptional,
            'missed_required' => $missedRequired,
            'next_missing' => $missingRequired[0] ?? $missingOptional[0] ?? null,
        ];
    }

    /**
     * The next week whose order deadline has not passed yet: this is the week the parents should
     * look at, because Mensamax pre-selects a menu and the selection can still be changed.
     *
     * @return array<string, mixed>|null
     */
    private function review(AccountSnapshot $snapshot, AccountConfig $account, DateTimeImmutable $thisMonday, DateTimeImmutable $now): ?array
    {
        for ($offset = 0; $offset < $account->lookaheadWeeks; $offset++) {
            $monday = $thisMonday->modify(sprintf('+%d weeks', $offset));
            $week = $this->weekView($snapshot, $account, $monday, $now);
            if ($week['order_deadline'] === null || !$week['editable']) {
                continue;
            }
            $relevantDays = [];
            foreach ($week['days'] as $dayView) {
                if ($dayView['offered'] && $dayView['kind'] !== DayKind::None->value) {
                    $date = new DateTimeImmutable($dayView['date'], $now->getTimezone());
                    $relevantDays[] = $this->dayView($snapshot->day($date), $date, $account, $now);
                }
            }
            if ($relevantDays === []) {
                continue;
            }
            $deadline = new DateTimeImmutable($week['order_deadline']);
            $secondsLeft = $deadline->getTimestamp() - $now->getTimestamp();
            $daysLeft = (int) ceil($secondsLeft / 86400);

            return [
                'week_monday' => $week['monday'],
                'iso_week' => $week['iso_week'],
                'label' => $week['label'],
                'order_deadline' => $week['order_deadline'],
                'days_until_deadline' => $daysLeft,
                'due' => $daysLeft <= $account->reviewWindowDays,
                'missing_required' => $week['missing_required'],
                'missing_optional' => $week['missing_optional'],
                'summary' => implode("\n", array_map(fn (array $d) => $d['summary'], $relevantDays)),
                'days' => $relevantDays,
            ];
        }

        return null;
    }

    private function short(string $main): string
    {
        return $this->shortTexts[$main] ?? $main;
    }
}
