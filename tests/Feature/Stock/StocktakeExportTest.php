<?php

namespace Tests\Feature\Stock;

use App\Authorization\Permissions;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\Range;
use App\Models\Stocktake;
use App\Models\User;
use App\Services\Stock\StocktakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Support\StockFixtures;
use Tests\TestCase;
use ZipArchive;

class StocktakeExportTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $manager;

    private User $staff;

    private Product $milk;

    private Product $beans;

    private Stocktake $stocktake;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-08 09:30:00', 'Australia/Brisbane'));
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->staff = $this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF);

        // Two bins whose walk order runs against their codes.
        $first = Location::create(['parent_id' => $this->site()->id, 'type' => 'bin', 'code' => 'Z-01', 'name' => 'Bin Z-01', 'walk_seq' => 1]);
        $second = Location::create(['parent_id' => $this->site()->id, 'type' => 'bin', 'code' => 'A-01', 'name' => 'Bin A-01', 'walk_seq' => 2]);

        $dairy = Range::create(['name' => 'Dairy']);
        $this->milk = Product::factory()->withPack('Carton', 12)->create(['sku' => 'MILK', 'name' => 'Milk', 'avg_cost' => '1.25', 'range_id' => $dairy->id, 'is_batch_tracked' => true]);
        $this->beans = Product::factory()->create(['sku' => 'BEANS', 'name' => 'Beans', 'avg_cost' => '4.5']);
        $this->stockIn($this->milk, 40, $first, Batch::findOrCreateFor($this->milk, 'LOT7', '2026-12-01'));
        $this->stockIn($this->beans, 10, $second);

        $this->stocktake = app(StocktakeService::class)->create($this->manager, $this->site(), 'Monthly count');
        $milkLine = $this->stocktake->lines()->where('product_id', $this->milk->id)->sole();
        // Milk: 36 counted against 40, so 4 short; beans left uncounted.
        app(StocktakeService::class)->recordCount($milkLine, 36, $this->staff);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function export(User $user, string $kind, string $format): TestResponse
    {
        return $this->actingAs($user)->get(route('stocktakes.export', [$this->stocktake, $kind, $format]));
    }

    /** Rows of the only sheet, as lists of cell values keyed by 0-based column. */
    private function sheet(string $bytes, ?string &$xml = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xt');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'a valid zip');
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/styles.xml', 'xl/_rels/workbook.xml.rels'] as $part) {
            $this->assertNotFalse(simplexml_load_string($zip->getFromName($part)), "$part is well-formed");
        }
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc, 'the sheet is well-formed');
        $rows = [];
        foreach ($doc->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                preg_match('/^([A-Z]+)/', (string) $c['r'], $m);
                $col = 0;
                foreach (str_split($m[1]) as $ch) {
                    $col = $col * 26 + ord($ch) - 64;
                }
                $cells[$col - 1] = (string) $c['t'] === 'inlineStr' ? (string) $c->is->t : (isset($c->v) ? (string) $c->v : null);
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /** The table: header row onward, each row keyed by column name. */
    private function table(array $rows): array
    {
        $at = array_key_first(array_filter($rows, fn ($r) => ($r[0] ?? null) === 'Bin'));
        $header = $rows[$at];

        return array_map(fn ($r) => array_combine($header, array_map(fn ($i) => $r[$i] ?? null, array_keys($header))), array_slice($rows, $at + 1));
    }

    public function test_results_xlsx_for_a_manager_has_costs_walk_order_and_totals()
    {
        $res = $this->export($this->manager, 'results', 'xlsx')->assertOk();

        $res->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertSame('attachment; filename="'.$this->stocktake->number.'-results-2026-11-08.xlsx"', $res->headers->get('Content-Disposition'));
        $rows = $this->sheet($res->getContent());

        $this->assertSame(['Stocktake', $this->stocktake->number], $rows[0]);
        $this->assertSame(['Status', 'Counting'], $rows[2]);
        $this->assertStringStartsWith($this->manager->name.', 2026-11-08 09:30', $rows[3][1]);
        $this->assertSame(['Note', 'Monthly count'], $rows[5]);

        [$milk, $beans, $totals] = $this->table($rows);
        $this->assertSame('MILK', $milk['SKU'], 'Z-01 is walked first');
        $this->assertSame(['Dairy', 'LOT7', '2026-12-01'], [$milk['Range'], $milk['Batch lot'], $milk['Best-before']]);
        $this->assertSame(['40', '36', '3 Carton'], [$milk['Snapshot (base)'], $milk['Counted (base)'], $milk['Counted (packs)']]);
        $this->assertSame(['-4', '4 Each short'], [$milk['Variance (base)'], $milk['Variance (packs)']]);
        $this->assertSame(['1.25', '-5'], [$milk['Unit cost'], $milk['Variance $ at cost']]);
        $this->assertSame($this->staff->name, $milk['Counted by']);
        $this->assertSame('2026-11-08 09:30', $milk['Counted at']);

        $this->assertSame('BEANS', $beans['SKU']);
        $this->assertNull($beans['Counted (base)'], 'uncounted is blank, not 0');
        $this->assertNull($beans['Counted (packs)']);
        $this->assertNull($beans['Variance (base)']);
        $this->assertNull($beans['Variance $ at cost']);

        $this->assertSame(['Totals', '2 lines', '1 uncounted'], [$totals['Bin'], $totals['SKU'], $totals['Product']]);
        $this->assertSame(['-4', '-5'], [$totals['Variance (base)'], $totals['Variance $ at cost']]);

        $audit = AuditLog::where('event', 'stocktake_exported')->sole();
        $this->assertSame(['export' => 'results', 'format' => 'xlsx', 'costs' => true, 'lines' => 2], $audit->meta);
        $this->assertSame($this->manager->id, $audit->user_id);
    }

    public function test_staff_get_the_results_without_cost_columns()
    {
        $res = $this->export($this->staff, 'results', 'xlsx')->assertOk();

        $table = $this->table($this->sheet($res->getContent()));
        $this->assertArrayNotHasKey('Unit cost', $table[0]);
        $this->assertArrayNotHasKey('Variance $ at cost', $table[0]);
        $this->assertSame('-4', $table[0]['Variance (base)']);
        $this->assertStringNotContainsString('1.25', $res->getContent());
        $this->assertFalse(AuditLog::where('event', 'stocktake_exported')->sole()->meta['costs']);

        $csv = $this->export($this->staff, 'results', 'csv')->assertOk()->getContent();
        $this->assertStringNotContainsString('Unit cost', $csv);
        $this->assertStringNotContainsString('at cost', $csv);
    }

    public function test_results_csv_matches_and_opens_cleanly_in_excel()
    {
        $res = $this->export($this->manager, 'results', 'csv')->assertOk();

        $this->assertSame('attachment; filename="'.$this->stocktake->number.'-results-2026-11-08.csv"', $res->headers->get('Content-Disposition'));
        $body = $res->getContent();
        $this->assertStringStartsWith("\u{FEFF}Stocktake,", $body);
        $rows = array_map('str_getcsv', explode("\n", trim(substr($body, 3))));
        $table = $this->table($rows);
        $this->assertSame(['MILK', '36', '3 Carton', '-4', '4 Each short', '1.25', '-5'], [
            $table[0]['SKU'], $table[0]['Counted (base)'], $table[0]['Counted (packs)'], $table[0]['Variance (base)'],
            $table[0]['Variance (packs)'], $table[0]['Unit cost'], $table[0]['Variance $ at cost'],
        ]);
        $this->assertSame('', $table[1]['Counted (base)'], 'uncounted is blank, not 0');
        $this->assertSame('Totals', $table[2]['Bin']);
    }

    public function test_text_that_a_spreadsheet_would_run_is_quoted_in_csv()
    {
        $this->beans->update(['name' => '=HYPERLINK("http://x")']);

        $csv = $this->export($this->manager, 'results', 'csv')->getContent();

        $this->assertStringContainsString("\"'=HYPERLINK(\"\"http://x\"\")\"", $csv);
    }

    public function test_packs_mix_the_largest_packs_and_base_units()
    {
        $line = $this->stocktake->lines()->where('product_id', $this->milk->id)->sole();
        app(StocktakeService::class)->recordCount($line, 40, $this->staff);
        $this->milk->units()->create(['name' => 'Inner', 'base_qty' => 6]);
        app(StocktakeService::class)->recordCount($line, 46, $this->staff);

        $milk = $this->table($this->sheet($this->export($this->manager, 'results', 'xlsx')->getContent()))[0];

        $this->assertSame('3 Carton + 1 Inner + 4 Each', $milk['Counted (packs)']);
        $this->assertSame('1 Inner over', $milk['Variance (packs)']);
    }

    public function test_a_posted_stocktake_exports_what_went_to_the_ledger()
    {
        app(StocktakeService::class)->post($this->stocktake, $this->manager, zeroUncounted: true);

        $rows = $this->sheet($this->export($this->manager, 'results', 'xlsx')->getContent());

        $this->assertSame(['Status', 'Posted'], $rows[2]);
        $this->assertStringStartsWith($this->manager->name.', 2026-11-08', $rows[4][1]);
        [$milk, $beans, $totals] = $this->table($rows);
        $this->assertSame('-10', $beans['Variance (base)'], 'posted as zero');
        $this->assertSame('-45', $beans['Variance $ at cost']);
        $this->assertSame(['-14', '-50'], [$totals['Variance (base)'], $totals['Variance $ at cost']]);
        $this->assertSame('0 uncounted', $totals['Product']);
    }

    public function test_cancelled_stocktakes_export_too()
    {
        app(StocktakeService::class)->cancel($this->stocktake);

        $rows = $this->sheet($this->export($this->staff, 'results', 'xlsx')->getContent());

        $this->assertSame(['Status', 'Cancelled'], $rows[2]);
        $this->assertSame(['Posted', '—'], $rows[4]);
    }

    public function test_the_count_sheet_has_blank_boxes_no_quantities_and_a_signature_footer()
    {
        $res = $this->export($this->staff, 'count-sheet', 'xlsx')->assertOk();

        $this->assertSame('attachment; filename="'.$this->stocktake->number.'-count-sheet-2026-11-08.xlsx"', $res->headers->get('Content-Disposition'));
        $rows = $this->sheet($res->getContent(), $xml);
        $table = $this->table($rows);
        $this->assertSame(['Bin', 'SKU', 'Product', 'Range', 'Batch lot', 'Best-before', 'Pack', 'Counted (packs)', 'Counted (each)', 'Note'], array_keys($table[0]));
        $this->assertSame(['MILK', 'Carton of 12', null, null], [$table[0]['SKU'], $table[0]['Pack'], $table[0]['Counted (packs)'], $table[0]['Counted (each)']]);
        $this->assertSame('BEANS', $table[1]['SKU']);
        $this->assertCount(2, $table, 'no totals on a count sheet');
        $this->assertStringNotContainsString('Snapshot', $xml);
        $this->assertStringNotContainsString('<v>40</v>', $xml, 'no system quantities');
        $this->assertStringContainsString('Signature:', $xml);
        $this->assertStringContainsString('Page &amp;P of &amp;N', $xml);
        $this->assertMatchesRegularExpression('/<c r="H\d+" s="4"\/>/', $xml, 'boxed blank cells to write in');
        $this->assertSame(['export' => 'count-sheet', 'format' => 'xlsx', 'costs' => false, 'lines' => 2], AuditLog::where('event', 'stocktake_exported')->sole()->meta);
    }

    public function test_the_count_sheet_prints_as_a_pdf()
    {
        $res = $this->export($this->staff, 'count-sheet', 'pdf')->assertOk();

        $res->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('inline; filename="'.$this->stocktake->number.'-count-sheet-2026-11-08.pdf"', $res->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertSame(1, AuditLog::where('event', 'stocktake_exported')->count());
    }

    public function test_formats_each_export_doesnt_come_in_are_not_found()
    {
        $this->export($this->manager, 'results', 'pdf')->assertNotFound();
        $this->export($this->manager, 'count-sheet', 'csv')->assertNotFound();
        $this->actingAs($this->manager)->get("/stocktakes/{$this->stocktake->id}/export/everything/xlsx")->assertNotFound();
        $this->assertSame(0, AuditLog::where('event', 'stocktake_exported')->count());
    }

    public function test_someone_who_cant_count_cant_export()
    {
        $this->export($this->userWithRole(Permissions::ROLE_READ_ONLY), 'results', 'xlsx')->assertForbidden();
        $this->assertSame(0, AuditLog::where('event', 'stocktake_exported')->count());
    }
}
