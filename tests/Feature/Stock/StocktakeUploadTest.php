<?php

namespace Tests\Feature\Stock;

use App\Authorization\Permissions;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Models\User;
use App\Services\Documents\SpreadsheetReader;
use App\Services\Documents\XlsxWriter;
use App\Services\Stock\StocktakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class StocktakeUploadTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $manager;

    private User $staff;

    private Product $milk;

    private Product $beans;

    private Product $oil;

    private Location $binZ;

    private Location $binA;

    private Batch $lot7;

    private Stocktake $stocktake;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-08 09:30:00', 'Australia/Brisbane'));
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->staff = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);

        // The stocktake covers one zone; the site's STOCK bin is outside it.
        $zone = Location::create(['parent_id' => $this->site()->id, 'type' => Location::TYPE_ZONE, 'code' => 'CHILL', 'name' => 'Chiller']);
        $this->binZ = Location::create(['parent_id' => $zone->id, 'type' => 'bin', 'code' => 'Z-01', 'name' => 'Bin Z-01', 'walk_seq' => 1]);
        $this->binA = Location::create(['parent_id' => $zone->id, 'type' => 'bin', 'code' => 'A-01', 'name' => 'Bin A-01', 'walk_seq' => 2]);

        $this->milk = Product::factory()->withPack('Carton', 12)->create(['sku' => 'MILK', 'name' => 'Milk', 'is_batch_tracked' => true, 'avg_cost' => '1.25']);
        $this->beans = Product::factory()->create(['sku' => 'BEANS', 'name' => 'Beans', 'avg_cost' => '4.5']);
        $this->oil = Product::factory()->withPack('Case', 6)->create(['sku' => 'OIL', 'name' => 'Oil', 'avg_cost' => '9']);
        $this->lot7 = Batch::findOrCreateFor($this->milk, 'LOT7', '2026-12-01');
        $this->stockIn($this->milk, 40, $this->binZ, $this->lot7);
        $this->stockIn($this->beans, 10, $this->binA);
        $this->stockIn($this->oil, 6, $this->bin());

        $this->stocktake = app(StocktakeService::class)->create($this->manager, $zone);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function exported(string $kind, string $format, ?Stocktake $stocktake = null): array
    {
        $bytes = $this->actingAs($this->manager)->get(route('stocktakes.export', [$stocktake ?? $this->stocktake, $kind, $format]))->assertOk()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'stk');
        file_put_contents($path, $bytes);
        $rows = app(SpreadsheetReader::class)->rows($path, $format, 1000);
        unlink($path);

        return $rows;
    }

    /** Row key of the line for a SKU, under the header. */
    private static function rowOf(array $rows, string $sku): int
    {
        return array_key_first(array_filter($rows, fn ($r) => ($r[1] ?? null) === $sku));
    }

    /** Back to a workbook, row numbers kept. */
    private static function xlsx(array $rows): string
    {
        $xlsx = new XlsxWriter('STK');
        for ($i = 0; $i <= max(array_keys($rows)); $i++) {
            $xlsx->row($rows[$i] ?? []);
        }

        return $xlsx->toString();
    }

    private static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        rewind($out);

        return stream_get_contents($out);
    }

    private function upload(string $filename, string $bytes, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->manager)->from(route('stocktakes.show', $this->stocktake))
            ->post(route('stocktakes.upload', $this->stocktake), ['file' => UploadedFile::fake()->createWithContent($filename, $bytes)]);
    }

    private function preview(): ?array
    {
        return $this->actingAs($this->manager)->get(route('stocktakes.show', $this->stocktake))->assertOk()->viewData('page')['props']['upload'];
    }

    private function apply(?array $preview = null): TestResponse
    {
        return $this->actingAs($this->manager)->from(route('stocktakes.show', $this->stocktake))
            ->post(route('stocktakes.upload.apply', $this->stocktake), ['token' => ($preview ?? $this->preview())['token']]);
    }

    private function line(Product $product, Location $bin): ?StocktakeLine
    {
        return $this->stocktake->lines()->where('product_id', $product->id)->where('location_id', $bin->id)->first();
    }

    /** A plain sheet with the count sheet's columns, for rows written by hand. */
    private function sheet(array $rows): string
    {
        return self::csv([['Bin', 'SKU', 'Batch lot', 'Best-before', 'Counted (packs)', 'Counted (each)'], ...$rows]);
    }

    public function test_round_trip_export_count_sheet_fill_upload_preview_apply_post()
    {
        $rows = $this->exported('count-sheet', 'xlsx');
        $milk = self::rowOf($rows, 'MILK');
        $beans = self::rowOf($rows, 'BEANS');
        $rows[$milk][7] = '3';   // Counted (packs): 3 Carton
        $rows[$milk][8] = '2';   // Counted (each): + 2
        $rows[$beans][8] = '9';
        // Oil found in A-01, counted in two parts: one by full path, one by code alone.
        $rows[] = [$this->binA->path(), 'OIL', '', '', '', '', '', '1', '', ''];
        $rows[] = ['A-01', 'oil', '', '', '', '', '', '', '3', 'second pallet'];

        $this->upload('counts.xlsx', self::xlsx($rows))->assertSessionHasNoErrors()->assertRedirect();
        $preview = $this->preview();

        $this->assertSame('counts.xlsx', $preview['filename']);
        $this->assertSame(['matched' => 2, 'new' => 1, 'unchanged' => 0, 'errors' => 0, 'blank' => 0], $preview['summary']);
        $byS = collect($preview['matched'])->keyBy('sku');
        $this->assertSame([null, 38, '3 Carton + 2 each', -2], [$byS['MILK']['old'], $byS['MILK']['new'], $byS['MILK']['new_label'], $byS['MILK']['variance']]);
        $this->assertSame('LOT7 (BB 01/12/2026)', $byS['MILK']['batch']);
        $this->assertSame([9, -1], [$byS['BEANS']['new'], $byS['BEANS']['variance']]);
        [$oil] = $preview['new'];
        $this->assertSame(['OIL', $this->binA->path(), 9, '1 Case + 3 each', 9], [$oil['sku'], $oil['bin'], $oil['new'], $oil['new_label'], $oil['variance']]);
        $this->assertCount(2, $oil['rows'], 'two rows added together');
        $this->assertNull($this->line($this->milk, $this->binZ)->counted_qty, 'a preview writes nothing');

        $this->apply($preview)->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(Stocktake::STATUS_COUNTING, $this->stocktake->fresh()->status, 'applying never posts');
        $milkLine = $this->line($this->milk, $this->binZ);
        $this->assertSame([38, 40, $this->manager->id, 'uploaded from counts.xlsx'], [$milkLine->counted_qty, $milkLine->system_qty_at_count, $milkLine->counted_by, $milkLine->count_note]);
        $oilLine = $this->line($this->oil, $this->binA);
        $this->assertSame([0, 9], [$oilLine->snapshot_qty, $oilLine->counted_qty], 'added as found');
        $this->assertNull($this->preview(), 'the preview is gone once applied');

        $this->actingAs($this->manager)->post(route('stocktakes.post', $this->stocktake))->assertSessionHasNoErrors();

        $this->assertSame(Stocktake::STATUS_POSTED, $this->stocktake->fresh()->status);
        $this->assertSame(38, $this->balance($this->milk, $this->binZ, $this->lot7));
        $this->assertSame(9, $this->balance($this->beans, $this->binA));
        $this->assertSame(9, $this->balance($this->oil, $this->binA));
        $this->assertSame(6, $this->balance($this->oil), 'the STOCK bin was not in the count');

        $uploaded = AuditLog::where('event', 'stocktake_counts_uploaded')->sole();
        $this->assertSame(['file' => 'counts.xlsx', 'matched' => 2, 'new' => 1, 'unchanged' => 0, 'errors' => 0, 'blank' => 0], $uploaded->meta);
        $this->assertSame($this->manager->id, $uploaded->user_id);
        $this->assertSame(['file' => 'counts.xlsx', 'counted' => 2, 'added' => 1], AuditLog::where('event', 'stocktake_counts_applied')->sole()->meta);
    }

    public function test_a_results_csv_counts_in_base_units_and_skips_unchanged_lines()
    {
        app(StocktakeService::class)->recordCount($this->line($this->milk, $this->binZ), 36, $this->staff);
        $rows = $this->exported('results', 'csv');
        $header = array_flip($rows[array_key_first(array_filter($rows, fn ($r) => ($r[0] ?? null) === 'Bin'))]);
        $this->assertSame('3 Carton', $rows[self::rowOf($rows, 'MILK')][$header['Counted (packs)']], 'a label, ignored on upload');
        $rows[self::rowOf($rows, 'BEANS')][$header['Counted (base)']] = '12';

        $this->upload('STK-results.csv', self::csv($rows))->assertSessionHasNoErrors();
        $preview = $this->preview();

        $this->assertSame(['matched' => 1, 'new' => 0, 'unchanged' => 1, 'errors' => 0, 'blank' => 0], $preview['summary'], 'the totals row is skipped');
        $this->assertSame('BEANS', $preview['matched'][0]['sku']);

        $this->apply($preview)->assertSessionHasNoErrors();

        $milk = $this->line($this->milk, $this->binZ);
        $this->assertSame([36, $this->staff->id, null], [$milk->counted_qty, $milk->counted_by, $milk->count_note], 'an unchanged count is left as it was');
        $this->assertSame(12, $this->line($this->beans, $this->binA)->counted_qty);
    }

    public function test_errors_are_listed_by_row_and_hold_the_whole_file_back()
    {
        $this->upload('bad.csv', $this->sheet([
            ['A-01', 'BEANS', '', '', '', '4'],              // row 2: fine on its own
            ['A-01', 'NOPE', '', '', '', '1'],
            ['Q-99', 'BEANS', '', '', '', '1'],
            ['Z-01', 'MILK', '', '', '1', ''],
            ['A-01', 'BEANS', '', '', '', '-3'],
            [$this->bin()->path(), 'OIL', '', '', '1', ''],
            ['A-01', 'BEANS', 'L1', '', '', '1'],
            ['Z-01', 'MILK', 'LOT7', '2026-02-31', '1', ''],
            ['Z-01', 'MILK', 'LOT7', '', '1.5', ''],
        ]))->assertSessionHasNoErrors();
        $preview = $this->preview();

        $this->assertSame([
            ['row' => 3, 'message' => 'Unknown SKU NOPE.'],
            ['row' => 4, 'message' => 'Unknown bin Q-99.'],
            ['row' => 5, 'message' => 'MILK is batch-tracked: fill in the lot.'],
            ['row' => 6, 'message' => 'Counted (each) is negative (-3).'],
            ['row' => 7, 'message' => "Bin {$this->bin()->path()} is outside this stocktake's scope."],
            ['row' => 8, 'message' => 'BEANS is not batch-tracked: leave the lot empty.'],
            ['row' => 9, 'message' => 'Best-before 2026-02-31 is not a date (use YYYY-MM-DD).'],
            ['row' => 10, 'message' => 'Counted (packs) 1.5 is not a whole number.'],
        ], $preview['errors']);
        $this->assertSame(1, $preview['summary']['matched']);

        $this->apply($preview)->assertSessionHasErrors(['stocktake' => 'Fix the errors in the file and upload it again.']);
        $this->assertNull($this->line($this->beans, $this->binA)->counted_qty, 'nothing applied');
    }

    public function test_packs_and_base_must_agree_and_excel_dates_are_read()
    {
        $serial = (string) Carbon::create(1899, 12, 30)->diffInDays(Carbon::create(2026, 12, 1));
        $this->upload('mixed.csv', self::csv([
            ['Bin', 'SKU', 'Batch lot', 'Best-before', 'Counted (packs)', 'Counted (each)', 'Counted (base)'],
            ['Z-01', 'MILK', 'LOT7', $serial, '2', '', '24'],
            ['A-01', 'BEANS', '', '', '1', '', '2'],
        ]))->assertSessionHasNoErrors();
        $preview = $this->preview();

        $this->assertSame([['row' => 3, 'message' => 'Counted (packs) and (each) make 1, but Counted (base) says 2. Fill in one or the other.']], $preview['errors']);
        $this->assertSame([24, $this->line($this->milk, $this->binZ)->id], [$preview['matched'][0]['new'], $preview['matched'][0]['line_id']]);
    }

    public function test_blank_rows_leave_counts_as_they_are()
    {
        app(StocktakeService::class)->recordCount($this->line($this->milk, $this->binZ), 36, $this->staff);
        $this->upload('part.csv', $this->sheet([
            ['Z-01', 'MILK', 'LOT7', '2026-12-01', '', ''],
            ['A-01', 'BEANS', '', '', '', '0'],
        ]))->assertSessionHasNoErrors();
        $preview = $this->preview();

        $this->assertSame([['row' => 2, 'sku' => 'MILK', 'bin' => 'Z-01', 'counted' => '3 Carton']], $preview['blank']);
        $this->apply($preview)->assertSessionHasNoErrors();

        $this->assertSame(36, $this->line($this->milk, $this->binZ)->counted_qty);
        $this->assertSame(0, $this->line($this->beans, $this->binA)->counted_qty, 'a 0 is a count, not a blank');
    }

    public function test_a_found_line_in_a_new_lot_creates_the_batch()
    {
        $this->upload('found.csv', $this->sheet([['A-01', 'MILK', 'NEW9', '15/01/2027', '1', '']]))->assertSessionHasNoErrors();
        $preview = $this->preview();
        $this->assertSame('NEW9 (BB 2027-01-15)', $preview['new'][0]['batch']);

        $this->apply($preview)->assertSessionHasNoErrors();

        $batch = Batch::where('lot_code', 'NEW9')->sole();
        $this->assertSame('2027-01-15', $batch->best_before->format('Y-m-d'));
        $this->assertSame(12, $this->stocktake->lines()->where('batch_id', $batch->id)->sole()->counted_qty);
    }

    public function test_a_file_from_another_stocktake_or_without_columns_is_refused()
    {
        $other = app(StocktakeService::class)->create($this->manager, $this->site());
        $rows = $this->exported('count-sheet', 'xlsx', $other);

        $this->upload('other.xlsx', self::xlsx($rows))->assertSessionHasErrors(['file' => "This file is for {$other->number}, not {$this->stocktake->number}."]);
        $this->upload('cols.csv', self::csv([['Bin', 'SKU', 'Qty'], ['A-01', 'BEANS', '3']]))
            ->assertSessionHasErrors(['file' => 'The file has no count columns: Counted (packs), Counted (each) or Counted (base).']);
        $this->assertNull($this->preview());
    }

    public function test_only_managers_upload_and_only_while_counting()
    {
        $file = $this->sheet([['A-01', 'BEANS', '', '', '', '4']]);
        $this->upload('s.csv', $file, $this->staff)->assertForbidden();

        app(StocktakeService::class)->cancel($this->stocktake);
        $this->upload('s.csv', $file)->assertSessionHasErrors(['file' => "Stocktake {$this->stocktake->number} is cancelled."]);
    }

    public function test_the_preview_expires_after_30_minutes()
    {
        $this->upload('late.csv', $this->sheet([['A-01', 'BEANS', '', '', '', '4']]));
        $preview = $this->preview();

        $this->travel(31)->minutes();

        $this->assertNull($this->preview());
        $this->apply($preview)->assertSessionHasErrors(['stocktake' => 'That upload has expired or was already applied. Upload the file again.']);
        $this->assertNull($this->line($this->beans, $this->binA)->counted_qty);
    }

    public function test_a_count_typed_in_after_the_preview_is_not_overwritten()
    {
        $this->upload('race.csv', $this->sheet([['A-01', 'BEANS', '', '', '', '4']]));
        $preview = $this->preview();
        app(StocktakeService::class)->recordCount($this->line($this->beans, $this->binA), 7, $this->staff);

        $this->apply($preview)->assertSessionHasErrors(['stocktake' => "BEANS in {$this->binA->path()} was counted since the preview. Upload the file again."]);

        $this->assertSame(7, $this->line($this->beans, $this->binA)->counted_qty);
    }

    public function test_discard_drops_the_preview()
    {
        $this->upload('x.csv', $this->sheet([['A-01', 'BEANS', '', '', '', '4']]));
        $preview = $this->preview();

        $this->actingAs($this->manager)->delete(route('stocktakes.upload.discard', $this->stocktake))->assertRedirect();

        $this->assertNull($this->preview());
        $this->apply($preview)->assertSessionHasErrors('stocktake');
    }
}
