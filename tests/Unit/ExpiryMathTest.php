<?php

namespace Tests\Unit;

use App\Services\Stock\ExpiringSoon;
use PHPUnit\Framework\TestCase;

class ExpiryMathTest extends TestCase
{
    public function test_sells_in_time_when_demand_covers_stock_ahead_and_the_batch()
    {
        // 3 a day for 5 days = 15; 4 ahead, 10 of this batch: 11 ≤ 15.
        $this->assertSame(['sold' => 10, 'left' => 0], ExpiringSoon::project(10, 4, 3.0, 5));
    }

    public function test_stock_ahead_is_sold_first()
    {
        // 2 a day for 5 days = 10; the 4 ahead go first, leaving 6 of this batch sold, 4 left.
        $this->assertSame(['sold' => 6, 'left' => 4], ExpiringSoon::project(10, 4, 2.0, 5));
        // So much ahead that none of this batch sells.
        $this->assertSame(['sold' => 0, 'left' => 10], ExpiringSoon::project(10, 30, 2.0, 5));
    }

    public function test_part_units_round_down_and_no_sales_or_no_days_sell_nothing()
    {
        $this->assertSame(['sold' => 3, 'left' => 2], ExpiringSoon::project(5, 0, 0.7, 5)); // 3.5 → 3
        $this->assertSame(['sold' => 0, 'left' => 5], ExpiringSoon::project(5, 0, 0.0, 30));
        $this->assertSame(['sold' => 0, 'left' => 5], ExpiringSoon::project(5, 0, 4.0, 0));
    }
}
