<?php

namespace Tests\Feature\Reports;

use App\Authorization\Permissions;
use App\Enums\MovementType;
use App\Models\Product;
use App\Services\Stock\GiveawayService;
use App\Services\Stock\MovementDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class InsightDashboardTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    public function test_dashboard_shows_this_months_shrinkage_giveaways_and_top_loss_to_managers()
    {
        $this->seedStockReferenceData();
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        [$milk, $bread] = [Product::factory()->create(['sku' => 'MILK']), Product::factory()->create(['sku' => 'BREAD'])];
        foreach ([[$milk, 2.0], [$bread, 1.0]] as [$p, $cost]) {
            $p->forceFill(['avg_cost' => $cost])->save();
            $this->stockIn($p, 100);
        }
        $loss = fn (Product $p, int $qty) => $this->ledger()->postOne(new MovementDraft(product: $p->fresh(), location: $this->bin(), qty: -$qty,
            type: MovementType::StocktakeVariance, idempotencyKey: 't:'.Str::uuid(), unitCost: (string) $p->fresh()->avg_cost), $manager);
        $loss($milk, 5);   // $10
        $loss($bread, 3);  // $3
        $this->ledger()->postOne(new MovementDraft(product: $milk->fresh(), location: $this->bin(), qty: -5, type: MovementType::StocktakeVariance,
            idempotencyKey: 'old:'.Str::uuid(), unitCost: '2.0000', occurredAt: now()->subMonths(2)), $manager); // not this month
        app(GiveawayService::class)->record($manager, $bread->fresh(), null, 4, $this->bin(), null, $this->reason('GIFT'), null, 'Food bank', 'Donation',
            $manager, (string) Str::uuid());

        $this->actingAs($manager)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('widgets.insights.shrinkage', 13)
                ->where('widgets.insights.giveaways', 4)
                ->where('widgets.insights.giveaway_budget', null)
                ->where('widgets.insights.top_loss.sku', 'MILK')
                ->where('widgets.insights.top_loss.value', 10));

        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->missing('widgets.insights'));
    }
}
