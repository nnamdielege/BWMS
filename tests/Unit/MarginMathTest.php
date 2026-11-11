<?php

namespace Tests\Unit;

use App\Services\Reports\MarginMath;
use PHPUnit\Framework\TestCase;

class MarginMathTest extends TestCase
{
    public function test_gm_percent_is_margin_over_sell()
    {
        $this->assertSame(33.33, MarginMath::gmPercent(18.0, 12.0));
        $this->assertSame(0.0, MarginMath::gmPercent(10.0, 10.0));
        $this->assertSame(-25.0, MarginMath::gmPercent(8.0, 10.0)); // sold below cost
        $this->assertNull(MarginMath::gmPercent(0.0, 5.0));
        $this->assertNull(MarginMath::gmPercent(null, 5.0));
        $this->assertNull(MarginMath::gmPercent(10.0, null));
    }

    public function test_weighted_gm_uses_totals_not_an_average_of_percentages()
    {
        // 90% on $10 and 10% on $1000: the average of percentages says 50%, the money says 10.79%.
        $sell = 10 + 1000;
        $cost = 1 + 900;
        $this->assertSame(10.79, MarginMath::weightedGmPercent($sell, $cost));
    }

    public function test_break_even_rounds_up_to_whole_packs()
    {
        $this->assertSame(6, MarginMath::breakEvenUnits(30, 18, 13));   // 30 / 5 = 6 exactly
        $this->assertSame(7, MarginMath::breakEvenUnits(30, 18, 13.5)); // 30 / 4.5 = 6.67
        $this->assertSame(1, MarginMath::breakEvenUnits(30, 100, 10));
        $this->assertSame(0, MarginMath::breakEvenUnits(0, 18, 13));
    }

    public function test_break_even_ignores_float_noise()
    {
        // 1.1 - 0.1 is 1.0000000000000002 in floats; 30 of them is still exactly 30 packs, not 31.
        $this->assertSame(30, MarginMath::breakEvenUnits(30, 1.1, 0.1));
        $this->assertSame(100, MarginMath::breakEvenUnits(30, 0.7, 0.4)); // 0.3 margin
    }

    public function test_break_even_is_null_when_no_pack_ever_covers_delivery()
    {
        $this->assertNull(MarginMath::breakEvenUnits(30, 10, 10));
        $this->assertNull(MarginMath::breakEvenUnits(30, 8, 10));
    }

    public function test_drop_sales_cost_gp_and_net()
    {
        $this->assertSame(
            ['sales' => 108.0, 'cost' => 72.0, 'gp' => 36.0, 'net' => 6.0, 'gm_percent' => 33.33, 'break_even' => 5],
            MarginMath::drop(6, 18, 12, 30),
        );
        $loss = MarginMath::drop(6, 10, 8, 30);
        $this->assertSame(12.0, $loss['gp']);
        $this->assertSame(-18.0, $loss['net']);
        $this->assertSame(15, $loss['break_even']);
    }

    public function test_pack_conversion()
    {
        $this->assertSame(12.0, MarginMath::perPack(2.0, 6));      // avg cost per base unit × pack size
        $this->assertSame(2.5, MarginMath::perPack(2.5, 0));      // a missing pack size counts as 1
        $this->assertSame(24.0, MarginMath::convertPackPrice(12, 6, 12)); // $12 a 6-pack = $24 a 12-pack
        $this->assertSame(4.0, MarginMath::convertPackPrice(24, 6, 1));
        $this->assertSame(24.0, MarginMath::convertPackPrice(4, 1, 6));
    }
}
