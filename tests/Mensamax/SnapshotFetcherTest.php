<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Tests\Mensamax;

use Etobi\Mensamax\Mensamax\SnapshotFetcher;
use Etobi\Mensamax\Tests\FixtureHelper;
use PHPUnit\Framework\TestCase;

final class SnapshotFetcherTest extends TestCase
{
    public function testParsesAllDaysSortedByDate(): void
    {
        $days = SnapshotFetcher::parseDays(FixtureHelper::rawMenuPlan(), FixtureHelper::timezone());

        self::assertCount(21, $days);
        self::assertSame('2026-08-31', array_key_first($days));
        self::assertSame('2026-09-20', array_key_last($days));
        self::assertSame(array_keys($days), array_map(fn ($d) => $d->isoDate(), array_values($days)));
    }

    public function testParsesMenusWithNumberGroupCoursesAndOrder(): void
    {
        $days = SnapshotFetcher::parseDays(FixtureHelper::rawMenuPlan(), FixtureHelper::timezone());
        $day = $days['2026-08-31'];

        self::assertCount(4, $day->menus);
        self::assertCount(3, $day->offeredMenus(), 'Lunchpaket is hidden (meinSpeiseplanShow=false)');
        self::assertSame([1, 2, 3, 4], array_map(fn ($m) => $m->number, $day->menus));
        self::assertSame('Menü 1 (vegetarisch)', $day->menus[0]->group);
        self::assertStringStartsWith('Gnocchi mit Möhrenrahm-Sauce', $day->menus[0]->main);

        $order = $day->orderedMenu();
        self::assertNotNull($order);
        self::assertSame(2, $order->number);
        self::assertSame(4.49, $order->price);
        self::assertStringStartsWith('Schweinebraten', $order->main);
        self::assertSame('Frisches Obst', $order->dessert);
        self::assertSame('', $order->starter);
        self::assertStringStartsWith('Schweinebraten', $order->fullText());
        self::assertStringEndsWith(', Frisches Obst', $order->fullText());
    }

    public function testParsesDeadlinesInLocalTimezone(): void
    {
        $days = SnapshotFetcher::parseDays(FixtureHelper::rawMenuPlan(), FixtureHelper::timezone());

        self::assertSame('2026-09-02T00:05:00+02:00', $days['2026-09-07']->orderDeadline?->format(DATE_ATOM));
        self::assertSame('2026-09-07T08:00:00+02:00', $days['2026-09-07']->cancelDeadline?->format(DATE_ATOM));
        self::assertNull($days['2026-09-05']->orderDeadline, 'weekend has no menus and no deadline');
        self::assertTrue($days['2026-09-07']->isEditable(FixtureHelper::now('2026-09-01 23:00:00')));
        self::assertFalse($days['2026-09-07']->isEditable(FixtureHelper::now('2026-09-02 00:06:00')));
    }

    public function testWeekendAndHiddenFridaysAreNotOffered(): void
    {
        $days = SnapshotFetcher::parseDays(FixtureHelper::rawMenuPlan(), FixtureHelper::timezone());

        self::assertFalse($days['2026-09-05']->isOffered());
        self::assertFalse($days['2026-09-04']->isOffered(), 'Friday menus exist but are hidden for this account');
        self::assertTrue($days['2026-09-07']->isOffered());
        self::assertNull($days['2026-09-07']->orderedMenu());
    }

    public function testHolidayMessageIsKept(): void
    {
        $raw = [[
            'datum' => '2026-10-03T00:00:00.000+02:00',
            'message' => 'Tag der Deutschen Einheit',
            'menues' => [],
        ]];
        $days = SnapshotFetcher::parseDays($raw, FixtureHelper::timezone());

        self::assertSame('Tag der Deutschen Einheit', $days['2026-10-03']->message);
        self::assertFalse($days['2026-10-03']->isOffered());
        self::assertSame(6, $days['2026-10-03']->weekday());
    }

    public function testCourseTextJoinsNameAndDescription(): void
    {
        $raw = [[
            'datum' => '2026-09-01T00:00:00.000+02:00',
            'menues' => [[
                'id' => 1, 'reihenfolge' => 1, 'meinSpeiseplanShow' => true, 'preis' => '3.5',
                'menuegruppe' => ['bezeichnung' => 'Menü 1'],
                'hauptspeisen' => [['bezeichnung' => '  Nudeln ', 'beschreibung' => "mit\n Sauce"]],
                'vorspeisen' => [['bezeichnung' => 'Salat', 'beschreibung' => null]],
                'nachspeisen' => [],
                'meineBestellung' => ['anzahl' => 2],
            ]],
        ]];
        $menu = SnapshotFetcher::parseDays($raw, FixtureHelper::timezone())['2026-09-01']->menus[0];

        self::assertSame('Nudeln mit Sauce', $menu->main);
        self::assertSame('Salat', $menu->starter);
        self::assertSame('', $menu->dessert);
        self::assertSame(3.5, $menu->price);
        self::assertSame(2, $menu->orderedQuantity);
        self::assertSame('Salat, Nudeln mit Sauce', $menu->fullText());
    }
}
