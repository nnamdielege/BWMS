<?php

namespace Tests\Feature\Catalogue;

use App\Authorization\Permissions;
use App\Jobs\FetchOrdermentumCatalogue;
use App\Jobs\ProcessOrdermentumOrder;
use App\Models\AuditLog;
use App\Models\ChannelListing;
use App\Models\ChannelUnmappedVariant;
use App\Models\IntegrationInbox;
use App\Models\Product;
use App\Models\Range;
use App\Models\Reservation;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Catalogue\Import\ImportFileReader;
use App\Services\Catalogue\Import\ImportPreviewStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;
use ZipArchive;

class ProductImportTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private const GTIN = '9300000000019';

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        config([
            'services.ordermentum.api_key' => 'test-key', 'services.ordermentum.supplier_id' => 'sup-1', 'services.ordermentum.location_id' => 'loc-1',
            'services.ordermentum.base_url' => 'https://api.test', 'bwms.ordermentum.ingest_enabled' => true,
        ]);
    }

    /** A variant in the shape the live API returns: unit fields say nothing, the barcode is internal. */
    private function variant(string $id, string $sku, string $name, array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'productId' => "p-{$id}", 'SKU' => $sku, 'name' => $name, 'price' => '37.5200', 'barcode' => 'x6e06507d',
            'unit' => 'unit', 'uom' => 'unit', 'unitSize' => 1, 'packingUnit' => 1, 'visible' => true, 'deactivatedAt' => null, 'deletedAt' => null,
        ];
    }

    private function fakeOrdermentum(array $variants, array $categories = []): void
    {
        Http::fake(function (Request $request) use ($variants, $categories) {
            $this->assertSame('GET', $request->method(), 'The import only reads Ordermentum');
            $data = str_contains($request->url(), '/v1/products')
                ? array_map(fn ($v) => ['id' => $v['productId'], 'categoryNames' => $categories[$v['id']] ?? []], $variants)
                : $variants;

            return Http::response(['data' => $data, 'meta' => ['totalPages' => 1, 'totalResults' => count($data)]]);
        });
    }

    private function dryRun(string $source = 'ordermentum')
    {
        return $this->artisan('products:import', ['source' => $source, '--dry-run' => true]);
    }

    private function importNow(string $source = 'ordermentum'): void
    {
        $this->artisan('products:import', ['source' => $source, '--yes' => true])->assertSuccessful();
    }

    private function csv(array $rows, array $columns = ImportFileReader::COLUMNS): UploadedFile
    {
        $lines = [implode(',', $columns)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn ($c) => $row[$c] ?? '', $columns));
        }

        return UploadedFile::fake()->createWithContent('products.csv', implode("\n", $lines)."\n");
    }

    private function upload(UploadedFile $file): array
    {
        $this->actingAs($this->manager)->post(route('product-import.upload'), ['file' => $file])->assertSessionHasNoErrors();

        return $this->previewFor();
    }

    private function previewFor(): array
    {
        return app(ImportPreviewStore::class)->get($this->manager->id, session('product_import_token'));
    }

    private function applyPreview(array $edits = [])
    {
        return $this->actingAs($this->manager)->post(route('product-import.apply'), ['token' => session('product_import_token'), 'edits' => $edits]);
    }

    private function rowsBySku(array $preview): array
    {
        return array_column($preview['rows'], null, 'sku');
    }

    /** As the legacy migration leaves a product that has had Pack setup: Each + Case. */
    private function packedProduct(string $sku = 'SODA-12', int $case = 12, array $attrs = []): Product
    {
        $p = Product::factory()->create($attrs + ['sku' => $sku, 'name' => 'Soda 330ml x 12', 'sell_channels' => 'none']);
        $p->units()->update(['name' => 'Each']);
        $p->units()->create(['name' => 'Case', 'base_qty' => $case, 'sellable' => true, 'purchasable' => true]);

        return $p->fresh('units');
    }

    private function listing(Product $p, string $channel, string $variant, int $qty = 12): ChannelListing
    {
        return ChannelListing::create(['channel' => $channel, 'product_id' => $p->id, 'external_variant_id' => $variant,
            'product_unit_id' => $p->units()->where('base_qty', $qty)->value('id')]);
    }

    public function test_an_ordermentum_variant_becomes_a_product_with_packs_and_a_listing_with_sync_off()
    {
        $this->fakeOrdermentum([$this->variant('v-1', '283991', 'Strawberry Matcha Giddy Fizz Soda 330ml x 12')], ['v-1' => ['Healthy Sodas']]);

        $this->importNow();

        $p = Product::where('sku', '283991')->sole();
        $this->assertSame('Strawberry Matcha Giddy Fizz Soda 330ml x 12', $p->name);
        $this->assertSame('330ml bottle', $p->base_unit);
        $this->assertSame('ordermentum', $p->sell_channels);
        $this->assertSame('37.52', (string) $p->sell_price);
        $this->assertSame('Healthy Sodas', $p->range->name);
        $this->assertSame([['Each', 1, false], ['Case', 12, true]], $p->units->map(fn ($u) => [$u->name, $u->base_qty, $u->default_sale])->all());

        $listing = ChannelListing::sole();
        $this->assertSame(['ordermentum', 'v-1', 'p-v-1', '283991', 'loc-1'], [$listing->channel, $listing->external_variant_id, $listing->external_product_id, $listing->external_sku, $listing->external_location_id]);
        $this->assertSame(12, $listing->unit->base_qty);
        $this->assertFalse($listing->sync_enabled, 'Pushing stays a decision on the Sync screen');
        $this->assertNull($listing->push_requested_at);
        $this->assertSame(0, $p->units->flatMap->barcodes->count(), 'The "x6e06507d" barcode is not a GTIN');
    }

    public function test_a_dry_run_prints_the_summary_and_saves_nothing_and_a_real_run_needs_yes()
    {
        $this->fakeOrdermentum([
            $this->variant('v-1', 'A-1', 'Soda 330ml x 12'),
            $this->variant('v-2', 'A-2', 'Old Soda 330ml x 12', ['deactivatedAt' => '2026-01-01', 'visible' => false]),
        ]);

        $this->dryRun()->expectsOutputToContain('Dry run: nothing was saved.')->expectsOutputToContain('Barcodes ignored, not GTINs: 2')->assertSuccessful();
        $this->artisan('products:import', ['source' => 'ordermentum'])->expectsOutputToContain('--yes')->assertFailed();
        $this->assertSame(0, Product::count());
    }

    public function test_the_portal_source_says_how_to_import_it_and_invents_nothing()
    {
        Http::fake();

        $this->artisan('products:import', ['source' => 'portal', '--dry-run' => true])->expectsOutputToContain('no way to read the portal catalogue')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_matching_by_sku_ignores_case_and_adds_the_mapping_to_the_existing_pack()
    {
        $p = $this->packedProduct();
        $this->fakeOrdermentum([$this->variant('v-1', 'soda-12', 'Soda 330ml x 12')]);

        $this->importNow();

        $this->assertSame(1, Product::count());
        $listing = ChannelListing::sole();
        $this->assertSame($p->id, $listing->product_id);
        $this->assertSame(12, $listing->unit->base_qty, 'Case ×12 is reused, and not added again');
        $this->assertSame(2, $p->units()->count());
        $this->assertSame('ordermentum', $p->fresh()->sell_channels, 'Mapping adds the channel, as the Map action does');
    }

    public function test_matching_by_mapping_when_the_sku_changed_at_the_source()
    {
        $p = $this->packedProduct();
        $this->listing($p, 'ordermentum', 'v-1');
        $this->fakeOrdermentum([$this->variant('v-1', 'RENAMED', 'Soda 330ml x 12')], ['v-1' => ['Sodas']]);

        $this->importNow();

        $this->assertSame(1, Product::count());
        $this->assertSame('SODA-12', $p->fresh()->sku);
        $this->assertSame('Sodas', $p->fresh()->range->name);
    }

    public function test_matching_by_barcode_when_sku_and_mapping_find_nothing()
    {
        $p = $this->packedProduct();
        $p->units->firstWhere('base_qty', 12)->barcodes()->create(['barcode' => self::GTIN, 'type' => 'ean13']);

        $this->upload($this->csv([['sku' => 'OTHER', 'name' => 'Soda 330ml x 12', 'barcode' => self::GTIN, 'ordermentum_variant_id' => 'v-9']]));
        $row = $this->previewFor()['rows'][0];
        $this->assertSame([$p->id, 'barcode', 'mapping'], [$row['product']['id'], $row['matched_by'], $row['group']]);
        $this->applyPreview()->assertSessionHas('success');

        $this->assertSame(1, Product::count());
        $this->assertSame($p->id, ChannelListing::where('external_variant_id', 'v-9')->value('product_id'));
    }

    public function test_matches_pointing_at_different_products_are_a_conflict_and_change_nothing()
    {
        $bySku = $this->packedProduct('SKU-A');
        $byMap = $this->packedProduct('SKU-B');
        $this->listing($byMap, 'ordermentum', 'v-1');
        $this->fakeOrdermentum([$this->variant('v-1', 'SKU-A', 'Soda 330ml x 12')]);

        $this->dryRun()->expectsOutputToContain('SKU-A')->assertSuccessful();
        $this->importNow();

        $this->assertSame(1, ChannelListing::count());
        $this->assertSame($byMap->id, ChannelListing::sole()->product_id);
        $this->assertSame(0, AuditLog::where('event', 'like', 'product_import%')->count());
        $this->assertSame('none', $bySku->fresh()->sell_channels);
    }

    public function test_edited_fields_stay_without_a_tick_and_change_with_one()
    {
        $p = $this->packedProduct(attrs: ['name' => 'Soda (our name)', 'is_batch_tracked' => true, 'shelf_life_days' => 30]);
        $this->listing($p, 'ordermentum', 'v-1');
        $rows = [['sku' => 'SODA-12', 'name' => 'Soda from the file', 'batch_tracked' => 'no', 'shelf_life_days' => '90', 'active' => 'no', 'pack_name' => 'Case', 'pack_size' => '12']];

        $row = $this->upload($this->csv($rows))['rows'][0];
        $this->assertSame('update', $row['group']);
        $this->assertSame(['shelf_life_days', 'name', 'is_batch_tracked', 'is_active'], array_column($row['diffs'], 'field'));
        $this->applyPreview()->assertSessionHas('success');

        $p->refresh();
        $this->assertSame(['Soda (our name)', true, 30, true], [$p->name, $p->is_batch_tracked, $p->shelf_life_days, $p->is_active]);
        $this->assertSame(0, AuditLog::where('event', 'like', 'product_import%')->count(), 'A row with no ticks changes nothing');

        $row = $this->upload($this->csv($rows))['rows'][0];
        $this->applyPreview([$row['key'] => ['overwrite' => true]])->assertSessionHas('success');

        $p->refresh();
        $this->assertSame(['Soda from the file', false, 90, false], [$p->name, $p->is_batch_tracked, $p->shelf_life_days, $p->is_active]);
    }

    public function test_empty_fields_fill_in_without_a_tick()
    {
        $p = $this->packedProduct(attrs: ['shelf_life_days' => null, 'sell_price' => null]);
        $p->units->firstWhere('base_qty', 12)->forceFill(['default_sale' => true])->save();
        $this->listing($p, 'ordermentum', 'v-1');

        $this->upload($this->csv([['sku' => 'SODA-12', 'name' => 'Soda 330ml x 12', 'range' => 'Sodas', 'shelf_life_days' => '90', 'barcode' => self::GTIN, 'ordermentum_variant_id' => 'v-1']]));
        $this->applyPreview()->assertSessionHas('success');

        $p->refresh();
        $this->assertSame(['Sodas', 90], [$p->range->name, $p->shelf_life_days]);
        $this->assertSame(self::GTIN, $p->units->firstWhere('base_qty', 12)->barcodes->sole()->barcode, 'The barcode goes on the pack the channel sells');
    }

    public function test_a_different_pack_size_is_never_changed_and_with_a_tick_the_listing_moves_to_a_new_pack()
    {
        $p = $this->packedProduct(case: 24);
        $listing = $this->listing($p, 'ordermentum', 'v-1', 24);
        $this->fakeOrdermentum([$this->variant('v-1', 'SODA-12', 'Soda 330ml x 12')]);
        $file = fn () => $this->csv([['sku' => 'SODA-12', 'name' => 'Soda 330ml x 12', 'pack_name' => 'Tray', 'pack_size' => '12', 'ordermentum_variant_id' => 'v-1']]);

        $row = $this->upload($file())['rows'][0];
        $this->assertSame(['pack'], array_column($row['diffs'], 'field'));
        $this->applyPreview()->assertSessionHas('success');
        $this->assertSame(24, $listing->fresh()->unit->base_qty);
        $this->assertSame(2, $p->units()->count());

        $row = $this->upload($file())['rows'][0];
        $this->applyPreview([$row['key'] => ['overwrite' => true]])->assertSessionHas('success');

        $this->assertSame(12, $listing->fresh()->unit->base_qty);
        $this->assertSame([1, 12, 24], $p->units()->pluck('base_qty')->all(), 'Case ×24 keeps its size: pack sizes are immutable');
    }

    public function test_a_pack_name_already_used_at_another_size_is_a_conflict_until_renamed()
    {
        $p = $this->packedProduct(case: 24);

        $row = $this->upload($this->csv([['sku' => 'SODA-12', 'name' => 'Soda 330ml x 12', 'pack_name' => 'Case', 'pack_size' => '12']]))['rows'][0];
        $this->assertSame('conflict', $row['group']);
        $this->assertStringContainsString('Case pack is ×24', $row['reason']);

        $this->actingAs($this->manager)->put(route('product-import.revise'), ['token' => session('product_import_token'), 'edits' => [$row['key'] => ['pack_name' => 'Tray']]])->assertSessionHasNoErrors();
        $this->assertSame('update', $this->previewFor()['rows'][0]['group']);
        $this->applyPreview([$row['key'] => ['pack_name' => 'Tray']])->assertSessionHas('success');

        $this->assertSame(12, $p->units()->where('name', 'Tray')->value('base_qty'));
    }

    public function test_a_product_on_the_default_unit_pack_gets_no_extra_pack_and_maps_to_unit()
    {
        $p = Product::factory()->create(['sku' => 'MIG-1', 'name' => 'Soda 330ml x 12', 'base_unit' => 'unit', 'sell_channels' => 'none']);
        $p->units()->update(['name' => 'Unit']);
        $this->fakeOrdermentum([$this->variant('v-1', 'MIG-1', 'Soda 330ml x 12')]);

        $this->importNow();

        $this->assertSame(['Unit'], $p->units()->pluck('name')->all(), 'A ×12 pack on a Unit that is a carton would mean 12 cartons');
        $this->assertSame('Unit', ChannelListing::sole()->unit->name);
    }

    public function test_the_same_sku_from_both_channels_is_one_product_with_two_mappings()
    {
        $row = ['sku' => 'BOTH-1', 'name' => 'Soda 330ml x 12', 'pack_name' => 'Case', 'pack_size' => '12'];
        $preview = $this->upload($this->csv([
            $row + ['ordermentum_variant_id' => 'v-1', 'sell_on_ordermentum' => 'yes'],
            $row + ['portal_item_id' => '77', 'sell_on_portal' => 'yes'],
        ]));
        $this->assertSame(['new', 'mapping'], array_column($preview['rows'], 'group'));
        $this->applyPreview()->assertSessionHas('success');

        $p = Product::where('sku', 'BOTH-1')->sole();
        $this->assertSame('both', $p->sell_channels);
        $this->assertEqualsCanonicalizing(['ordermentum', 'portal'], $p->channelListings()->pluck('channel')->all());
        $this->assertSame(1, $p->channelListings()->pluck('product_unit_id')->unique()->count());
        $this->assertSame('77', $p->channelListings()->where('channel', 'portal')->value('external_product_id'));
    }

    public function test_one_row_can_carry_both_channels_and_a_portal_import_later_only_adds_its_mapping()
    {
        $this->upload($this->csv([['sku' => 'ROW-1', 'name' => 'Soda 330ml x 12', 'pack_size' => '12', 'ordermentum_variant_id' => 'v-1', 'portal_item_id' => '5']]));
        $this->applyPreview();
        $this->assertSame('both', Product::sole()->sell_channels);
        $this->assertSame(2, ChannelListing::count());

        $this->fakeOrdermentum([$this->variant('v-2', 'ORD-1', 'Other 330ml x 12')]);
        $this->importNow();
        $this->upload($this->csv([['sku' => 'ORD-1', 'name' => 'Other 330ml x 12', 'portal_item_id' => '6']]));
        $this->assertSame('mapping', $this->previewFor()['rows'][0]['group']);
        $this->applyPreview();

        $this->assertSame(2, Product::count());
        $this->assertSame('both', Product::where('sku', 'ORD-1')->value('sell_channels'));
    }

    public function test_an_unparseable_pack_is_check_pack_and_needs_a_value_before_it_applies()
    {
        $this->fakeOrdermentum([$this->variant('v-1', 'SYRUP-1', 'Dash Syrup 750ml'), $this->variant('v-2', 'MISC', 'misc - gst free')]);

        $this->dryRun()->expectsOutputToContain('Check pack (a value is needed): 2')->assertSuccessful();
        $this->importNow();
        $this->assertSame(0, Product::count(), 'The command has no way to fill them in, so it leaves them out');

        $preview = $this->upload($this->csv([['sku' => 'SYRUP-1', 'name' => 'Dash Syrup 750ml']]));
        $row = $preview['rows'][0];
        $this->assertTrue($row['check_pack']);
        $this->assertSame(['Each', 1], [$row['suggested']['pack_name'], $row['suggested']['pack_size']]);

        $this->applyPreview()->assertSessionHasErrors('edits');
        $this->assertSame(0, Product::count());

        $this->applyPreview([$row['key'] => ['pack_name' => 'Each', 'pack_size' => 1, 'base_unit' => '750ml bottle']])->assertSessionHas('success');
        $p = Product::sole();
        $this->assertSame('750ml bottle', $p->base_unit);
        $this->assertSame(['Each'], $p->units()->pluck('name')->all());
        $this->assertTrue($p->baseUnit->default_sale);
    }

    public function test_a_new_mapping_on_an_existing_product_never_assumes_a_pack()
    {
        $this->packedProduct('SYRUP-1');

        $row = $this->upload($this->csv([['sku' => 'SYRUP-1', 'name' => 'Soda 330ml x 12', 'ordermentum_variant_id' => 'v-1', 'pack_size' => '']]))['rows'][0];
        $this->assertSame('mapping', $row['group'], 'The name reads as Case ×12');

        $row = $this->upload($this->csv([['sku' => 'SYRUP-1', 'name' => 'Soda', 'portal_item_id' => '8']]))['rows'][0];
        $this->assertTrue($row['check_pack']);
    }

    public function test_excluded_rows_are_left_out_and_excluding_one_of_a_pair_clears_the_conflict()
    {
        $this->fakeOrdermentum([$this->variant('v-1', 'LOCSEL6', 'Best Picks 330ml x 12'), $this->variant('v-2', 'LOCSEL6', 'Super Saver Set 330ml x 12')]);

        $this->actingAs($this->manager)->post(route('product-import.fetch'))->assertRedirect();
        $preview = $this->previewFor();
        $this->assertSame(['conflict', 'conflict'], array_column($preview['rows'], 'group'));

        $this->applyPreview(['Vv-2' => ['included' => false]])->assertSessionHas('success');

        $this->assertSame('Best Picks 330ml x 12', Product::sole()->name);
        $this->assertSame('v-1', ChannelListing::sole()->external_variant_id);
    }

    public function test_a_deactivated_item_is_skipped_unless_included_and_then_comes_in_inactive()
    {
        $old = $this->variant('v-1', 'OLD-1', 'Old Soda 330ml x 12', ['deactivatedAt' => '2026-01-01', 'visible' => false]);
        $this->fakeOrdermentum([$old]);

        $this->actingAs($this->manager)->post(route('product-import.fetch'));
        $row = $this->previewFor()['rows'][0];
        $this->assertSame(['skipped', 'Deactivated on Ordermentum.'], [$row['group'], $row['reason']]);

        $this->applyPreview([$row['key'] => ['included' => true]])->assertSessionHas('success');
        $this->assertFalse(Product::sole()->is_active);
    }

    public function test_preview_edits_shape_the_new_product()
    {
        $row = $this->upload($this->csv([['sku' => 'EDIT-1', 'name' => 'Soda 330ml x 12', 'ordermentum_variant_id' => 'v-1']]))['rows'][0];

        $this->applyPreview([$row['key'] => ['pack_name' => 'Tray', 'pack_size' => 6, 'range' => 'Kombucha', 'batch_tracked' => true, 'sell_portal' => true]])->assertSessionHas('success');

        $p = Product::sole();
        $this->assertSame([1, 6], $p->units->pluck('base_qty')->all());
        $this->assertSame('Tray', $p->units->last()->name);
        $this->assertTrue($p->is_batch_tracked);
        $this->assertSame('Kombucha', $p->range->name);
        $this->assertSame('both', $p->sell_channels);
        $this->assertSame(6, ChannelListing::sole()->unit->base_qty);
    }

    public function test_batch_tracking_never_flips_on_a_product_with_stock_movements()
    {
        $p = $this->packedProduct();
        $this->receive($p, 5);
        $row = $this->upload($this->csv([['sku' => 'SODA-12', 'name' => 'Soda 330ml x 12', 'batch_tracked' => 'yes']]))['rows'][0];

        $this->assertStringContainsString('stock movements', implode(' ', $row['notes']));
        $this->applyPreview([$row['key'] => ['overwrite' => true]]);

        $this->assertFalse($p->fresh()->is_batch_tracked);
    }

    public function test_an_import_creates_and_changes_no_stock()
    {
        $p = $this->packedProduct();
        $this->receive($p, 5);
        $before = [StockMovement::count(), StockBalance::count(), StockBalance::sum('on_hand'), Reservation::count()];
        $this->fakeOrdermentum([$this->variant('v-1', 'SODA-12', 'Soda 330ml x 12'), $this->variant('v-2', 'NEW-1', 'New 330ml x 12')], ['v-1' => ['Sodas']]);

        $this->importNow();

        $this->assertSame(2, Product::count());
        $this->assertSame($before, [StockMovement::count(), StockBalance::count(), StockBalance::sum('on_hand'), Reservation::count()]);
    }

    public function test_held_events_replay_for_newly_mapped_items_and_the_queue_entry_resolves()
    {
        Queue::fake();
        $inbox = IntegrationInbox::create(['channel' => 'ordermentum', 'event' => 'poll', 'external_id' => 'o-1', 'dedupe_key' => str_repeat('a', 64), 'payload' => '{}',
            'status' => IntegrationInbox::STATUS_NEEDS_MAPPING, 'error' => 'Unmapped variants: v-1']);
        $other = IntegrationInbox::create(['channel' => 'ordermentum', 'event' => 'poll', 'external_id' => 'o-2', 'dedupe_key' => str_repeat('b', 64), 'payload' => '{}',
            'status' => IntegrationInbox::STATUS_NEEDS_MAPPING, 'error' => 'Unmapped variants: v-other']);
        $variant = ChannelUnmappedVariant::create(['channel' => 'ordermentum', 'external_variant_id' => 'v-1', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $this->fakeOrdermentum([$this->variant('v-1', 'NEW-1', 'New 330ml x 12')]);

        $this->importNow();

        Queue::assertPushed(ProcessOrdermentumOrder::class, fn ($j) => $j->orderId === 'o-1' && $j->inboxId === $inbox->id);
        Queue::assertNotPushed(ProcessOrdermentumOrder::class, fn ($j) => $j->orderId === 'o-2');
        $this->assertSame(IntegrationInbox::STATUS_RECEIVED, $inbox->fresh()->status);
        $this->assertSame(IntegrationInbox::STATUS_NEEDS_MAPPING, $other->fresh()->status);
        $this->assertNotNull($variant->fresh()->resolved_at);
    }

    public function test_each_product_gets_one_audit_entry_with_its_source()
    {
        $existing = $this->packedProduct();
        $mappedOnly = $this->packedProduct('MAP-1');
        $mappedOnly->forceFill(['range_id' => Range::create(['name' => 'Sodas'])->id])->save();
        $this->fakeOrdermentum([
            $this->variant('v-1', 'NEW-1', 'New 330ml x 12'),
            $this->variant('v-2', 'SODA-12', 'Soda 330ml x 12'),
            $this->variant('v-3', 'MAP-1', 'Soda 330ml x 12'),
        ], ['v-2' => ['Sodas']]);
        $audited = AuditLog::count();

        $this->importNow();

        $created = Product::where('sku', 'NEW-1')->sole();
        $logs = AuditLog::where('id', '>', 0)->orderBy('id')->skip($audited)->take(100)->get();
        $this->assertSame([
            ['product_import_created', $created->id],
            ['product_import_updated', $existing->id],
            ['product_import_mapped', $mappedOnly->id],
        ], $logs->reject(fn ($l) => $l->auditable_type === (new Range)->getMorphClass())->map(fn ($l) => [$l->event, (int) $l->auditable_id])->values()->all());
        $this->assertSame(['ordermentum'], $logs->filter(fn ($l) => str_starts_with($l->event, 'product_import'))->pluck('meta.source')->unique()->values()->all());
    }

    public function test_batches_of_one_hundred_commit_on_their_own_so_a_failure_keeps_earlier_batches()
    {
        $rows = [];
        foreach (range(1, 105) as $i) {
            $rows[] = ['sku' => "BULK-{$i}", 'name' => "Soda {$i} 330ml x 12", 'ordermentum_variant_id' => "v-{$i}"];
        }
        $this->upload($this->csv($rows));

        // The last row's SKU is taken behind the preview's back, in a way only the insert notices.
        Product::saving(function (Product $p) {
            if ($p->sku === 'BULK-103') {
                throw new \RuntimeException('Boom');
            }
        });

        $this->applyPreview()->assertSessionHas('error', fn ($m) => str_contains($m, 'Created 100') && str_contains($m, 'Batch 2 was not saved'));

        $this->assertSame(100, Product::count());
        $this->assertSame(100, ChannelListing::count());
    }

    public function test_apply_checks_live_data_again_so_a_change_after_the_preview_is_not_overwritten()
    {
        $row = $this->upload($this->csv([['sku' => 'LATE-1', 'name' => 'Soda 330ml x 12', 'pack_size' => '12', 'ordermentum_variant_id' => 'v-1']]))['rows'][0];
        $this->assertSame('new', $row['group']);

        $someoneElse = $this->packedProduct('OTHER-1');
        $this->listing($someoneElse, 'ordermentum', 'v-1');

        $this->applyPreview()->assertSessionHas('success');

        $this->assertSame(0, Product::where('sku', 'LATE-1')->count());
        $this->assertSame($someoneElse->id, ChannelListing::sole()->product_id);
    }

    public function test_a_preview_applies_once_and_expires_after_thirty_minutes()
    {
        $this->upload($this->csv([['sku' => 'ONCE-1', 'name' => 'Soda 330ml x 12']]));
        $token = session('product_import_token');

        $this->applyPreview()->assertSessionHas('success');
        $this->actingAs($this->manager)->post(route('product-import.apply'), ['token' => $token])->assertSessionHas('error', fn ($m) => str_contains($m, 'expired'));
        $this->assertSame(1, Product::count());

        $this->upload($this->csv([['sku' => 'LATE-1', 'name' => 'Soda 330ml x 12']]));
        $this->travel(31)->minutes();
        $this->applyPreview()->assertSessionHas('error', fn ($m) => str_contains($m, 'expired'));
        $this->assertSame(1, Product::count());
    }

    public function test_the_screen_is_for_admins_and_managers_and_the_fetch_is_queued()
    {
        Queue::fake();
        $staff = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);
        $this->actingAs($staff)->get(route('product-import.index'))->assertForbidden();
        $this->actingAs($staff)->post(route('product-import.fetch'))->assertForbidden();
        $this->actingAs($this->userWithRole(Permissions::ROLE_PURCHASING))->get(route('product-import.index'))->assertForbidden();

        $this->actingAs($this->manager)->post(route('product-import.fetch'))->assertRedirect();

        Queue::assertPushed(FetchOrdermentumCatalogue::class);
        $this->actingAs($this->manager)->get(route('product-import.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('products/Import')->where('preview.status', 'fetching'));
    }

    public function test_a_failed_fetch_shows_on_the_screen_and_a_partial_list_is_refused()
    {
        Http::fake(fn () => Http::response(['data' => [['id' => 'p-1', 'categoryNames' => []]], 'meta' => ['totalPages' => 1, 'totalResults' => 2]]));

        $this->actingAs($this->manager)->post(route('product-import.fetch'));

        $this->actingAs($this->manager)->get(route('product-import.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('preview.status', 'failed')->where('preview.error', fn ($e) => str_contains($e, '1 of 2')));
    }

    public function test_the_template_has_the_columns_and_bad_cells_become_conflicts()
    {
        $this->actingAs($this->manager)->get(route('product-import.template'))
            ->assertOk()->assertStreamedContent(implode(',', ImportFileReader::COLUMNS)."\n");

        $row = $this->upload($this->csv([['sku' => 'BAD-1', 'name' => 'Soda 330ml x 12', 'pack_size' => 'twelve', 'batch_tracked' => 'maybe', 'barcode' => '9300000000018']]))['rows'][0];

        $this->assertSame('conflict', $row['group']);
        foreach (['pack_size must be', 'batch_tracked must be', 'Barcode 9300000000018 is not valid'] as $problem) {
            $this->assertStringContainsString($problem, $row['reason']);
        }
    }

    public function test_an_xlsx_reads_like_a_csv_even_with_empty_cells_left_out()
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>sku</t></si><si><t>name</t></si><si><t>pack_size</t></si><si><t>barcode</t></si><si><t>XL-1</t></si><si><r><t>Soda </t></r><r><t>330ml x 12</t></r></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
            .'<row r="3"><c r="A3" t="s"><v>4</v></c><c r="B3" t="s"><v>5</v></c><c r="D3"><v>9.300000000019E12</v></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();

        $items = app(ImportFileReader::class)->read($path, 'xlsx');
        unlink($path);

        $this->assertCount(1, $items);
        $this->assertSame(['XL-1', 'Soda 330ml x 12', null, self::GTIN, 3], [$items[0]['sku'], $items[0]['name'], $items[0]['pack_size'], $items[0]['barcode'], $items[0]['line']]);
    }

    public function test_the_command_imports_a_file()
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "sku,name,pack_size,portal_item_id\nFILE-1,Soda 330ml x 12,12,9\n");

        $this->importNow("file={$path}");
        unlink($path);

        $this->assertSame('portal', Product::sole()->sell_channels);
        $this->assertSame('file', AuditLog::where('event', 'product_import_created')->sole()->meta['source']);
    }

    /** Stock in, through the ledger, so the movement is real. */
    private function receive(Product $p, int $qty): void
    {
        $this->stockIn($p, $qty);
    }
}
