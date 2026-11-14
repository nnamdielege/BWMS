<?php

namespace Tests\Unit;

use App\Services\Purchasing\ReorderMath;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class ReorderMathTest extends TestCase
{
    /** 56 days, oldest first, built from 8 weekly rates (week 1 = most recent). */
    private function weeks(array $ratesNewestFirst): array
    {
        $days = [];
        foreach (array_reverse($ratesNewestFirst) as $rate) {
            array_push($days, ...array_fill(0, 7, $rate));
        }

        return $days;
    }

    public function test_steady_demand_is_its_daily_rate()
    {
        $d = ReorderMath::demand(array_fill(0, 56, 2));
        $this->assertEqualsWithDelta(2.0, $d['avg'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $d['std'], 1e-9);
        $this->assertSame(56, $d['days']);
    }

    public function test_recent_weeks_weigh_more()
    {
        // Only the latest week sold, 7 a day: weight 8 of 36.
        $recent = ReorderMath::demand($this->weeks([7, 0, 0, 0, 0, 0, 0, 0]));
        $this->assertEqualsWithDelta(7 * 8 / 36, $recent['avg'], 1e-9);
        // The same week eight weeks ago: weight 1 of 36.
        $old = ReorderMath::demand($this->weeks([0, 0, 0, 0, 0, 0, 0, 7]));
        $this->assertEqualsWithDelta(7 / 36, $old['avg'], 1e-9);
        $this->assertSame(8, $recent['weeks'][0]['weight']);
        $this->assertSame(49, $recent['weeks'][0]['sold']);
    }

    public function test_stock_out_days_are_left_out()
    {
        $days = array_fill(0, 56, 4);
        // Out of stock for the last 3 days: nothing sold, but it isn't low demand.
        $days[53] = $days[54] = $days[55] = null;
        $d = ReorderMath::demand($days);
        $this->assertEqualsWithDelta(4.0, $d['avg'], 1e-9);
        $this->assertSame(53, $d['days']);
        $this->assertSame(4, $d['weeks'][0]['days']);

        // Counted as zeros instead, demand would have dropped.
        $days[53] = $days[54] = $days[55] = 0;
        $this->assertLessThan(4.0, ReorderMath::demand($days)['avg']);
    }

    public function test_a_week_with_no_counted_days_is_skipped_not_zero()
    {
        $days = array_fill(0, 56, 3);
        for ($i = 49; $i < 56; $i++) {
            $days[$i] = null; // the whole latest week out of stock
        }
        $this->assertEqualsWithDelta(3.0, ReorderMath::demand($days)['avg'], 1e-9);
        $this->assertSame(0.0, ReorderMath::demand(array_fill(0, 56, null))['avg']);
    }

    public function test_std_dev_of_daily_demand()
    {
        $days = [];
        for ($i = 0; $i < 56; $i++) {
            $days[] = $i % 2 ? 4 : 0; // alternating 0 and 4: mean 2
        }
        $this->assertEqualsWithDelta(sqrt(56 * 4 / 55), ReorderMath::demand($days)['std'], 1e-9);
    }

    public function test_review_days_follow_order_days_then_review_period_then_default()
    {
        $monday = Carbon::parse('2026-11-16'); // a Monday
        $thursday = Carbon::parse('2026-11-19');
        $this->assertSame(3, ReorderMath::reviewDays([1, 4], null, 7, $monday)); // Mon → Thu
        $this->assertSame(4, ReorderMath::reviewDays([1, 4], null, 7, $thursday)); // Thu → Mon
        $this->assertSame(7, ReorderMath::reviewDays([1], 3, 9, $monday)); // weekly: next Monday, period ignored
        $this->assertSame(10, ReorderMath::reviewDays([], 10, 7, $monday));
        $this->assertSame(7, ReorderMath::reviewDays([], null, 7, $monday));
    }

    public function test_safety_stock_in_days_or_by_z()
    {
        $this->assertEqualsWithDelta(6.0, ReorderMath::safety('days', 2.0, 1.5, 4, 3, 1.65), 1e-9);
        $this->assertEqualsWithDelta(1.65 * 1.5 * 2, ReorderMath::safety('z', 2.0, 1.5, 4, 3, 1.65), 1e-9);
    }

    public function test_need_subtracts_available_and_open_purchase_orders()
    {
        // 2 a day over 12 days + 6 safety = 30, less 10 available and 6 on order.
        $this->assertEqualsWithDelta(14.0, ReorderMath::need(2.0, 12, 6.0, 10, 6), 1e-9);
        $this->assertEqualsWithDelta(-2.0, ReorderMath::need(2.0, 12, 6.0, 20, 12), 1e-9);
    }

    public function test_suggestions_round_up_to_the_pack_and_the_moq()
    {
        $this->assertSame(18, ReorderMath::suggest(14.0, 6, null)); // 2.33 packs → 3
        $this->assertSame(12, ReorderMath::suggest(12.0000001, 6, null)); // float noise is not a pack
        $this->assertSame(30, ReorderMath::suggest(1.0, 6, 5)); // 1 pack, MOQ 5 packs
        $this->assertSame(36, ReorderMath::suggest(31.0, 6, 5)); // above the MOQ: just rounded
        $this->assertSame(0, ReorderMath::suggest(0.0, 6, 5)); // nothing needed: no MOQ either
        $this->assertSame(0, ReorderMath::suggest(-4.0, 6, null));
        $this->assertSame(5, ReorderMath::suggest(4.2, 1, null));
    }

    public function test_days_left_and_trend()
    {
        $this->assertSame(5.0, ReorderMath::daysLeft(10, 2.0));
        $this->assertSame(0.0, ReorderMath::daysLeft(-3, 2.0));
        $this->assertNull(ReorderMath::daysLeft(10, 0.0));
        $this->assertSame('up', ReorderMath::trend(120, 100));
        $this->assertSame('down', ReorderMath::trend(80, 100));
        $this->assertSame('flat', ReorderMath::trend(105, 100));
        $this->assertSame('up', ReorderMath::trend(5, 0));
        $this->assertSame('flat', ReorderMath::trend(0, 0));
    }
}
