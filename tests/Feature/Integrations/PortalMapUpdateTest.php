<?php

namespace Tests\Feature\Integrations;

use App\Authorization\Permissions;
use App\Enums\MovementType;
use App\Models\AuditLog;
use App\Models\ChannelListing;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\Stock\MovementDraft;
use App\Services\Stock\StocktakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/** channels:portal-map --update: correcting the pack an existing portal listing sells. */
class PortalMapUpdateTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->file = sys_get_temp_dir().'/portal-update-'.uniqid().'.csv';
    }

    protected function tearDown(): void
    {
        File::delete($this->file);
        parent::tearDown();
    }

    /** A product mapped to the portal as Each ×1, like the five mapped by mistake. */
    private function mappedAsEach(string $sku, string $portalId, ?array $pack = null, bool $sync = false): Product
    {
        $factory = Product::factory();
        if ($pack) {
            $factory = $factory->withPack(...$pack);
        }
        $product = $factory->create(['sku' => $sku, 'base_unit' => 'bottle', 'sell_channels' => 'portal']);
        ChannelListing::create([
            'channel' => 'portal', 'product_id' => $product->id, 'product_unit_id' => $product->units()->where('base_qty', 1)->value('id'),
            'external_product_id' => $portalId, 'external_variant_id' => $portalId, 'external_sku' => $sku, 'sync_enabled' => $sync,
        ]);

        return $product;
    }

    private function opening(Product $product, int $qty): void
    {
        $this->ledger()->postOne(new MovementDraft(
            product: $product, location: $this->bin(), qty: $qty, type: MovementType::OpeningBalance, idempotencyKey: 'ob:'.Str::uuid(),
        ));
    }

    private function update(string $csv, bool $dryRun): array
    {
        File::put($this->file, $csv);
        $code = Artisan::call('channels:portal-map', ['file' => $this->file, '--update' => true, '--dry-run' => $dryRun]);

        return [$code, Artisan::output()];
    }

    private function packOf(string $portalId): int
    {
        return ChannelListing::where('channel', 'portal')->where('external_variant_id', $portalId)->sole()->unit->base_qty;
    }

    public function test_dry_run_reports_old_to_new_and_the_real_run_repoints_with_an_audit_entry()
    {
        $wine = $this->mappedAsEach('774400', '9001', ['Carton', 12], sync: true);
        $this->opening($wine, 36);
        // Portal ID left blank: the SKU has one portal listing.
        $csv = "portal_product_id,sku,pack_qty\n9001,774400,12\n";

        [$code, $out] = $this->update($csv, dryRun: true);

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/9001\s*\|\s*774400\s*\|\s*Each ×1\s*\|\s*Carton ×12\s*\|\s*would change/u', $out);
        $this->assertMatchesRegularExpression('/774400\s*\|\s*bottle\s*\|.*\|\s*36 = 3 Carton\s*\|\s*36 \(opening [\d-]+\)\s*\|\s*all multiples of 12: looks entered in base units/u', $out);
        $this->assertStringContainsString('Dry run: nothing was saved.', $out);
        $this->assertSame(1, $this->packOf('9001'));
        $this->assertSame(0, AuditLog::where('event', 'portal_listing_repointed')->count());

        [$code, $out] = $this->update("portal_product_id,sku,pack_qty\n,774400,12\n", dryRun: false);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('1 changed, 0 unchanged, 0 refused.', $out);
        $this->assertSame(12, $this->packOf('9001'));
        $audit = AuditLog::where('event', 'portal_listing_repointed')->sole();
        $this->assertSame(['Each ×1', 'Carton ×12'], [$audit->old_values['pack'], $audit->new_values['pack']]);

        [, $out] = $this->update($csv, dryRun: false);
        $this->assertStringContainsString('0 changed, 1 unchanged, 0 refused.', $out, 'running it again changes nothing');
    }

    public function test_a_missing_or_unsellable_pack_is_refused_and_nothing_is_created()
    {
        $this->mappedAsEach('TNBM', '9002');
        $wmwc = $this->mappedAsEach('WMWC', '9003', ['Case', 24]);
        $wmwc->units()->where('base_qty', 24)->update(['sellable' => false]);
        $this->mappedAsEach('774411', '9004', ['Carton', 12]);
        $units = ProductUnit::count();

        [$code, $out] = $this->update("portal_product_id,sku,pack_qty\n9002,TNBM,6\n9003,WMWC,24\n9004,774411,12\n9004,TNBM,1\n,NOPE,12\n,774411,\n", dryRun: false);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED: TNBM has no pack of 6 (it has Each ×1). Add the pack on the product page first.', $out);
        $this->assertStringContainsString("REFUSED: WMWC's Case ×24 isn't sellable.", $out);
        $this->assertStringContainsString('REFUSED: portal product 9004 is mapped to another product, not TNBM.', $out);
        $this->assertStringContainsString('REFUSED: unknown SKU NOPE.', $out);
        $this->assertStringContainsString('REFUSED: pack_qty is required with --update', $out);
        $this->assertStringContainsString('1 changed, 0 unchanged, 5 refused.', $out, 'the good row still goes through');
        $this->assertSame([1, 1, 12], [$this->packOf('9002'), $this->packOf('9003'), $this->packOf('9004')]);
        $this->assertSame($units, ProductUnit::count(), 'no pack is created');
    }

    public function test_the_stock_report_flags_counts_that_look_typed_in_packs()
    {
        $wine = $this->mappedAsEach('774422', '9005', ['Carton', 12]);
        $manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->opening($wine, 5);
        $stocktake = app(StocktakeService::class)->create($manager, $this->bin());
        app(StocktakeService::class)->recordCount($stocktake->lines()->sole(), 4, $manager);
        app(StocktakeService::class)->post($stocktake, $manager);

        [, $out] = $this->update("portal_product_id,sku,pack_qty\n9005,774422,12\n", dryRun: true);

        $this->assertMatchesRegularExpression('/774422\s*\|\s*bottle\s*\|.*\|\s*4 = 4 bottles\s*\|\s*5 \(opening [\d-]+\), 4 \(stocktake [\d-]+\)\s*\|\s*all under 12: may be cartons typed as bottle; check the shelf/u', $out);
    }
}
