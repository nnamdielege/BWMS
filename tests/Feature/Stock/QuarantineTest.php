<?php

namespace Tests\Feature\Stock;

use App\Authorization\Permissions;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\QuarantineEntry;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierCredit;
use App\Models\User;
use App\Services\Reports\SpoilageReport;
use App\Services\Stock\QuarantineRegister;
use App\Support\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class QuarantineTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $manager;

    private Product $yoghurt;

    private Batch $fresh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->yoghurt = Product::factory()->batchTracked()->create(['supplier_id' => Supplier::factory()->create(['name' => 'Dairy Co'])->id]);
        $this->yoghurt->forceFill(['avg_cost' => 2.5])->save();
        $this->fresh = $this->batch($this->yoghurt, 'Y1', Carbon::parse(BusinessDate::today())->addDays(20)->toDateString());
    }

    private function quarantine(): Location
    {
        return $this->bin('QUARANTINE');
    }

    private function moveIn(int $qty, ?string $reason, ?Batch $batch = null): TestResponse
    {
        return $this->actingAs($this->manager)->post(route('stock.transfer', $this->yoghurt), [
            'client_token' => (string) Str::uuid(), 'from_location_id' => $this->bin()->id, 'to_location_id' => $this->quarantine()->id,
            'each' => $qty, 'batch_id' => ($batch ?? $this->fresh)->id, 'quarantine_reason' => $reason, 'note' => 'Lids split',
        ]);
    }

    private function line(array $extra): array
    {
        return ['client_token' => (string) Str::uuid(), 'product_id' => $this->yoghurt->id, 'location_id' => $this->quarantine()->id,
            'batch_id' => $this->fresh->id] + $extra;
    }

    public function test_moving_stock_into_quarantine_asks_for_a_reason()
    {
        $this->stockIn($this->yoghurt, 10, null, $this->fresh);

        $this->moveIn(4, null)->assertSessionHasErrors('quarantine_reason');
        $this->assertSame(0, $this->balance($this->yoghurt, $this->quarantine(), $this->fresh));

        $this->moveIn(4, 'damaged')->assertSessionHasNoErrors();
        $this->assertSame(4, $this->balance($this->yoghurt, $this->quarantine(), $this->fresh));
        $entry = QuarantineEntry::sole();
        $this->assertSame('damaged', $entry->reason);
        $this->assertSame('Lids split', $entry->note);
        $this->assertSame($this->fresh->id, (int) $entry->batch_key);
    }

    public function test_register_shows_value_days_and_reason_since_the_line_arrived()
    {
        $this->stockIn($this->yoghurt, 10, null, $this->fresh);
        $this->travelTo(now()->subDays(5));
        $this->moveIn(4, 'quality')->assertSessionHasNoErrors();
        $this->travelBack();
        $this->moveIn(2, 'recall'); // more of the same line later: days still count from the first

        $r = app(QuarantineRegister::class)->build();
        $this->assertCount(1, $r['rows']);
        $row = $r['rows'][0];
        $this->assertSame(6, $row['qty']);
        $this->assertSame(15.0, $row['value']);
        $this->assertSame(5, $row['days']);
        $this->assertSame('Recall', $row['reason']); // the latest reason given
        $this->assertSame('Dairy Co', $row['supplier']);
        $this->assertFalse($row['expired']);
        $this->assertSame(['lines' => 1, 'units' => 6, 'value' => 15.0], $r['totals']);

        $this->actingAs($this->manager)->get(route('quarantine.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('stock/Quarantine')->has('register.rows', 1));
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->get(route('quarantine.index'))->assertForbidden();
    }

    public function test_write_off_needs_a_reason_and_counts_as_spoilage_at_cost()
    {
        $this->stockIn($this->yoghurt, 10, null, $this->fresh);
        $this->moveIn(6, 'damaged');

        $this->actingAs($this->manager)->post(route('quarantine.write-off'), $this->line(['qty' => 4]))->assertSessionHasErrors('reason_code_id');
        $this->actingAs($this->manager)->post(route('quarantine.write-off'), $this->line(['qty' => 4, 'reason_code_id' => $this->reason('DAMAGED')->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->balance($this->yoghurt, $this->quarantine(), $this->fresh));
        $m = StockMovement::where('type', 'write_off')->sole();
        $this->assertSame('2.5000', $m->unit_cost);
        $this->assertSame(10.0, app(SpoilageReport::class)->build(BusinessDate::today(), BusinessDate::today())['totals']['value']);
    }

    public function test_return_to_supplier_opens_a_credit_at_cost_that_can_be_marked_received()
    {
        $this->stockIn($this->yoghurt, 10, null, $this->fresh);
        $this->moveIn(6, 'recall');
        $supplier = Supplier::where('name', 'Dairy Co')->sole();
        $payload = $this->line(['qty' => 6, 'supplier_id' => $supplier->id, 'note' => 'RA 5521']);

        $this->actingAs($this->manager)->post(route('quarantine.return'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('quarantine.return'), $payload)->assertSessionHasNoErrors(); // double submit

        $this->assertSame(0, $this->balance($this->yoghurt, $this->quarantine(), $this->fresh));
        $credit = SupplierCredit::sole();
        $this->assertSame($supplier->id, $credit->supplier_id);
        $this->assertSame(6, $credit->qty_base);
        $this->assertSame('15.00', $credit->amount);
        $this->assertSame('open', $credit->status);
        $this->assertSame('return_to_supplier', $credit->movement->type->value);
        // A return isn't spoilage: the money comes back.
        $this->assertSame(0, app(SpoilageReport::class)->build(BusinessDate::today(), BusinessDate::today())['totals']['lines']);

        $this->actingAs($this->manager)->get(route('supplier-credits.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('purchasing/SupplierCredits')->where('total', 15)->where('by_supplier.0.supplier', 'Dairy Co'));

        $this->actingAs($this->manager)->post(route('supplier-credits.receive', $credit), ['credit_ref' => 'CN-88'])->assertSessionHasNoErrors();
        $credit->refresh();
        $this->assertSame('received', $credit->status);
        $this->assertSame('CN-88', $credit->credit_ref);
        $this->assertNotNull($credit->received_at);
        $this->actingAs($this->manager)->get(route('supplier-credits.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('total', 0)->has('rows', 0));

        $this->actingAs($this->userWithRole(Permissions::ROLE_READ_ONLY))->post(route('supplier-credits.receive', $credit))->assertForbidden();
    }

    public function test_release_needs_a_reason_and_a_sellable_bin_and_not_expired_stock()
    {
        $this->stockIn($this->yoghurt, 10, null, $this->fresh);
        $this->moveIn(5, 'quality');

        $this->actingAs($this->manager)->post(route('quarantine.release'), $this->line(['qty' => 5, 'to_location_id' => $this->bin()->id]))
            ->assertSessionHasErrors('note');
        $this->actingAs($this->manager)->post(route('quarantine.release'), $this->line(['qty' => 5, 'to_location_id' => $this->quarantine()->id, 'note' => 'Checked']))
            ->assertSessionHasErrors('qty');
        $this->actingAs($this->manager)->post(route('quarantine.release'), $this->line(['qty' => 5, 'to_location_id' => $this->bin()->id, 'note' => 'Checked, lids fine']))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->balance($this->yoghurt, $this->quarantine(), $this->fresh));
        $this->assertSame(10, $this->balance($this->yoghurt, null, $this->fresh));
        $this->assertStringContainsString('Checked, lids fine', StockMovement::where('type', 'transfer_in')->latest('id')->value('note'));
    }

    public function test_expired_stock_cannot_be_released()
    {
        $old = $this->batch($this->yoghurt, 'Y0', Carbon::parse(BusinessDate::today())->subDay()->toDateString());
        $this->stockIn($this->yoghurt, 3, $this->quarantine(), $old);

        $this->actingAs($this->manager)->post(route('quarantine.release'), $this->line(['batch_id' => $old->id, 'qty' => 3, 'to_location_id' => $this->bin()->id, 'note' => 'ok']))
            ->assertSessionHasErrors('qty');
        $this->assertSame(3, $this->balance($this->yoghurt, $this->quarantine(), $old));

        $row = app(QuarantineRegister::class)->build()['rows'][0];
        $this->assertTrue($row['expired']);
        $this->assertNull($row['reason']); // came in without a recorded reason
    }

    public function test_actions_only_take_stock_out_of_quarantine_bins()
    {
        $this->stockIn($this->yoghurt, 10, null, $this->fresh);

        $this->actingAs($this->manager)->post(route('quarantine.write-off'), $this->line(['location_id' => $this->bin()->id, 'qty' => 2, 'reason_code_id' => $this->reason('DAMAGED')->id]))
            ->assertSessionHasErrors('qty');
        $this->assertSame(10, $this->balance($this->yoghurt, null, $this->fresh));
    }
}
