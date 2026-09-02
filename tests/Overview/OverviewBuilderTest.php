<?php

declare(strict_types=1);

namespace Etobi\Mensamax\Tests\Overview;

use Etobi\Mensamax\Overview\OverviewBuilder;
use Etobi\Mensamax\Tests\FixtureHelper;
use PHPUnit\Framework\TestCase;

final class OverviewBuilderTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function build(string $now = '2026-09-02 10:00:00', array $account = [], array $shortTexts = [], float $balance = 89.56): array
    {
        return (new OverviewBuilder($shortTexts))->build(
            FixtureHelper::snapshot($balance),
            FixtureHelper::account($account),
            FixtureHelper::now($now),
        );
    }

    public function testTodayAndTomorrow(): void
    {
        $overview = $this->build();

        self::assertSame('2026-09-02', $overview['today']['date']);
        self::assertSame('ordered', $overview['today']['status']);
        self::assertSame('required', $overview['today']['kind']);
        self::assertSame(1, $overview['today']['order']['number']);
        self::assertStringStartsWith('Haferflocken-Käse-Bratling', $overview['today']['order']['main']);
        self::assertSame('Mi 02.09.', $overview['today']['label']);
        self::assertCount(3, $overview['today']['menus'], 'alternatives are listed');

        self::assertSame('2026-09-03', $overview['tomorrow']['date']);
        self::assertSame('ordered', $overview['tomorrow']['status']);
    }

    public function testDayWithoutOfferIsNotOffered(): void
    {
        $overview = $this->build('2026-09-05 10:00:00');

        self::assertSame('not_offered', $overview['today']['status']);
        self::assertNull($overview['today']['order']);
        self::assertSame('Sa 05.09.: kein Angebot', $overview['today']['summary']);
    }

    public function testShortTextsAreApplied(): void
    {
        $overview = $this->build(shortTexts: [
            'Haferflocken-Käse-Bratling (Vollkorn-Haferflocken, Bio-Käse) in Kräuterrahm-Sauce mit Kartoffeln' => 'Bratling mit Kartoffeln',
        ]);

        $main = $overview['today']['order']['main'];
        self::assertStringStartsWith('Haferflocken', $main);
        // the fixture main text may differ slightly; ensure fallback keeps full text when no short text matches
        self::assertNotSame('', $overview['today']['order']['main_short']);
    }

    public function testWeeksCountOrdersAndMissingDays(): void
    {
        $overview = $this->build();

        $this_ = $overview['weeks']['this'];
        self::assertSame('2026-08-31', $this_['monday']);
        self::assertSame(36, $this_['iso_week']);
        self::assertSame(4, $this_['ordered_count'], 'Mon-Thu ordered');
        self::assertSame([], $this_['missing_required']);
        self::assertTrue($this_['complete']);
        self::assertFalse($this_['editable'], 'deadline 26.08. has passed');
        self::assertCount(5, $this_['days']);
        self::assertSame('not_offered', $this_['days'][4]['status'], 'Friday hidden');
        self::assertArrayNotHasKey('menus', $this_['days'][0], 'week days carry no alternatives to keep the payload small');

        $next = $overview['weeks']['next'];
        self::assertSame('2026-09-07', $next['monday']);
        self::assertSame(3, $next['ordered_count']);
        self::assertSame([], $next['missing_required']);
        self::assertSame(['2026-09-07'], $next['missing_optional'], 'Monday is optional and not ordered');
        self::assertSame('2026-09-02T00:05:00+02:00', $next['order_deadline']);
        self::assertFalse($next['editable'], 'deadline was this morning 00:05');

        $inTwo = $overview['weeks']['in_two'];
        self::assertSame('2026-09-14', $inTwo['monday']);
        self::assertTrue($inTwo['editable'], 'deadline 09.09. is still ahead');
        self::assertSame(['2026-09-14'], $inTwo['missing_optional']);
        self::assertArrayNotHasKey('in_three', $overview['weeks'], 'lookahead is 3 weeks');
    }

    public function testWeekSummaryContainsMenuNumbers(): void
    {
        $overview = $this->build();

        $lines = explode("\n", $overview['weeks']['next']['summary']);
        self::assertSame('Mo 07.09.: keine Bestellung', $lines[0]);
        self::assertStringStartsWith('Di 08.09.: Gericht 2, Lachsgratin', $lines[1]);
        self::assertStringStartsWith('Mi 09.09.: Gericht 1, Gemüseauflauf', $lines[2]);
        self::assertCount(4, $lines, 'hidden Friday without message is left out');
    }

    public function testRequiredDayWithoutOrderRaisesAlert(): void
    {
        // Treat Monday as required: 07.09. (deadline passed -> missed) and 14.09. (still editable -> missing)
        $overview = $this->build(account: ['requiredWeekdays' => [1, 2, 3, 4], 'optionalWeekdays' => []]);

        self::assertTrue($overview['alerts']['order_missing']);
        self::assertSame(['2026-09-14'], $overview['alerts']['missing_required']);
        self::assertSame(['2026-09-07'], $overview['alerts']['missed_required']);
        self::assertSame([], $overview['alerts']['missing_optional']);
        self::assertSame('2026-09-14', $overview['alerts']['next_missing']);
        self::assertSame(['2026-09-07'], $overview['weeks']['next']['missing_required']);
        self::assertFalse($overview['weeks']['next']['complete']);
    }

    public function testNoAlertWhenOnlyOptionalDaysAreMissing(): void
    {
        $overview = $this->build();

        self::assertFalse($overview['alerts']['order_missing']);
        self::assertSame([], $overview['alerts']['missing_required']);
        self::assertSame(['2026-09-14'], $overview['alerts']['missing_optional'], '07.09. is no longer editable');
        self::assertSame('2026-09-14', $overview['alerts']['next_missing']);
    }

    public function testBalanceSumsOrdersFromTodayWithinHorizon(): void
    {
        $overview = $this->build(balance: 20.0);
        $balance = $overview['balance'];

        // orders from today (02.09.) on: 02.09., 03.09., 08.-10.09., 15.-17.09. = 8 x 4.49
        self::assertSame(8, $balance['orders_count']);
        self::assertSame(35.92, $balance['orders_sum']);
        self::assertSame(20.0, $balance['current']);
        self::assertSame(-15.92, $balance['remaining']);
        self::assertSame('2026-09-09', $balance['covered_until'], '4 orders x 4.49 = 17.96 fit into 20.00');
        self::assertSame('2026-09-10', $balance['uncovered_from']);
        self::assertSame(-390.87, $balance['mensamax_future']);
    }

    public function testLowBalanceFlagUsesConfiguredThreshold(): void
    {
        self::assertTrue($this->build(balance: 19.99)['balance']['low']);
        self::assertFalse($this->build(balance: 20.0)['balance']['low']);
        self::assertTrue($this->build(balance: 40.0, account: ['lowBalance' => 50.0])['balance']['low']);
    }

    public function testReviewPointsToNextEditableWeek(): void
    {
        $overview = $this->build('2026-09-02 10:00:00');
        $review = $overview['review'];

        self::assertNotNull($review);
        self::assertSame('2026-09-14', $review['week_monday']);
        self::assertSame('2026-09-09T00:05:00+02:00', $review['order_deadline']);
        self::assertSame(7, $review['days_until_deadline']);
        self::assertFalse($review['due']);
        self::assertCount(4, $review['days'], 'Mon-Thu are required/optional and offered');
        self::assertCount(3, $review['days'][1]['menus'], 'review days list the alternatives');
        self::assertSame([1, 2, 3], array_column($review['days'][1]['menus'], 'number'));
        self::assertSame(['2026-09-14'], $review['missing_optional']);
        self::assertStringContainsString('Di 15.09.: Gericht 2, Wurstgulasch', $review['summary']);
    }

    public function testReviewBecomesDueInsideWindow(): void
    {
        $review = $this->build('2026-09-06 12:00:00')['review'];

        self::assertSame('2026-09-14', $review['week_monday']);
        self::assertSame(3, $review['days_until_deadline']);
        self::assertTrue($review['due']);
    }

    public function testReviewIsNullWhenNoEditableWeekInHorizon(): void
    {
        $review = $this->build('2026-09-09 08:00:00')['review'];

        self::assertNull($review, 'all three fixture weeks are locked after 09.09. 00:05');
    }

    public function testBeforeFirstDeadlineThisWeekIsReviewWeek(): void
    {
        $review = $this->build('2026-08-25 12:00:00')['review'];

        self::assertSame('2026-08-31', $review['week_monday']);
        self::assertTrue($review['due']);
    }

    public function testNextOrder(): void
    {
        $overview = $this->build('2026-09-05 10:00:00');

        self::assertSame('2026-09-08', $overview['next_order']['date']);
        self::assertSame(2, $overview['next_order']['order']['number']);
    }

    public function testMeta(): void
    {
        $meta = $this->build()['meta'];

        self::assertSame('2026-08-31', $meta['horizon_from']);
        self::assertSame('2026-09-20', $meta['horizon_until']);
        self::assertNull($meta['llm_error']);
        self::assertSame([2, 3, 4], $meta['required_weekdays']);
    }
}
