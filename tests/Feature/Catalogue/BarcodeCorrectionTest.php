<?php

namespace Tests\Feature\Catalogue;

use App\Authorization\Permissions;
use App\Models\AuditLog;
use App\Models\Barcode;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Services\Orders\PickListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OrderFixtures;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class BarcodeCorrectionTest extends TestCase
{
    use OrderFixtures, RefreshDatabase, StockFixtures;

    private const GOOD = '9300000000019';

    private const OTHER = '9300000000026';

    private const THIRD = '9300000000033';

    private User $manager;

    private Product $milk;

    private Product $soy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->manager = $this->userWithRole(Permissions::ROLE_WAREHOUSE_MANAGER);
        $this->milk = Product::factory()->withPack('Carton', 12)->create(['sku' => 'MILK-1', 'name' => 'Oat milk']);
        $this->soy = Product::factory()->create(['sku' => 'SOY-1', 'name' => 'Soy milk']);
    }

    private function unit(Product $p, int $qty): ProductUnit
    {
        return $p->units()->where('base_qty', $qty)->firstOrFail();
    }

    private function barcodeOn(ProductUnit $u, string $code): Barcode
    {
        return $u->barcodes()->create(['barcode' => $code, 'type' => 'ean13']);
    }

    private function audit(string $event): AuditLog
    {
        return AuditLog::where('event', $event)->latest('id')->firstOrFail();
    }

    public function test_change_a_barcode_on_a_pack_is_checked_and_audited()
    {
        $b = $this->barcodeOn($this->unit($this->milk, 12), self::GOOD);
        $this->barcodeOn($this->unit($this->soy, 1), self::OTHER);

        $this->actingAs($this->manager)->put(route('barcodes.update', $b), ['barcode' => '9300000000010'])->assertSessionHasErrors('barcode'); // wrong check digit
        $this->actingAs($this->manager)->put(route('barcodes.update', $b), ['barcode' => self::OTHER])
            ->assertSessionHasErrors(['barcode' => 'This barcode is on SOY-1 Soy milk (Each).']);
        $this->assertSame(self::GOOD, $b->fresh()->barcode);

        $this->actingAs($this->manager)->put(route('barcodes.update', $b), ['barcode' => self::THIRD])->assertSessionHasNoErrors();
        $this->assertSame(self::THIRD, $b->fresh()->barcode);
        $log = $this->audit('barcode_changed');
        $this->assertSame([['barcode' => self::GOOD], ['barcode' => self::THIRD]], [$log->old_values, $log->new_values]);
        $this->assertSame(['product' => 'MILK-1 Oat milk', 'pack' => 'Carton'], $log->meta);
        $this->assertSame([$this->manager->id, $this->milk->id], [$log->user_id, (int) $log->auditable_id]);
    }

    public function test_remove_a_barcode_is_audited_and_warns_when_it_leaves_a_pack_bare_mid_pick()
    {
        $carton = $this->unit($this->milk, 12);
        $first = $this->barcodeOn($carton, self::GOOD);
        $last = $this->barcodeOn($carton, self::OTHER);

        $this->actingAs($this->manager)->delete(route('barcodes.destroy', $first))->assertSessionHasNoErrors()->assertSessionMissing('warning');
        $this->assertNull(Barcode::find($first->id));
        $log = $this->audit('barcode_removed');
        $this->assertSame([['barcode' => self::GOOD], 'Carton', $this->manager->id], [$log->old_values, $log->meta['pack'], $log->user_id]);

        // An open pick list still needs the product: allowed, with a warning.
        $this->stockIn($this->milk, 50);
        $order = $this->manualOrder($this->manager, $this->milk, 5);
        app(PickListService::class)->create($this->manager, $this->site(), [$order->id]);
        $this->actingAs($this->manager)->get(route('products.edit', $this->milk))->assertInertia(fn (AssertableInertia $p) => $p->where('openPickLists', 1));

        $this->actingAs($this->manager)->delete(route('barcodes.destroy', $last))->assertSessionHasNoErrors()
            ->assertSessionHas('warning', 'MILK-1 Carton now has no barcode, and 1 open pick list still need it: pickers will have to enter the quantity.');
        $this->assertSame(0, $carton->barcodes()->count());
    }

    public function test_capture_offers_move_it_here_and_moves_in_one_audited_step()
    {
        $b = $this->barcodeOn($this->unit($this->milk, 12), self::GOOD);
        $soyEach = $this->unit($this->soy, 1);

        $this->actingAs($this->manager)->post(route('barcode-capture.store'), ['product_unit_id' => $soyEach->id, 'barcode' => self::GOOD])
            ->assertSessionHasErrors(['barcode' => 'This barcode is on MILK-1 Oat milk (Carton).'])
            ->assertSessionHas('barcode_conflict', fn ($c) => $c['barcode_id'] === $b->id && $c['to_unit_id'] === $soyEach->id && $c['pack'] === 'Carton');
        $this->actingAs($this->manager)->withSession(['barcode_conflict' => ['barcode_id' => $b->id, 'to_unit_id' => $soyEach->id]])
            ->get(route('barcode-capture.index', ['product' => $this->soy->id]))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('conflict.barcode_id', $b->id)->where('canMove', true));

        $this->actingAs($this->manager)->post(route('barcode-capture.move'), ['barcode_id' => $b->id, 'product_unit_id' => $soyEach->id])
            ->assertRedirect(route('barcode-capture.index', ['product' => $this->soy->id]))->assertSessionHasNoErrors();
        $this->assertSame($soyEach->id, $b->fresh()->product_unit_id);
        $this->assertSame(1, Barcode::count());

        $logs = AuditLog::where('event', 'barcode_moved')->get();
        $this->assertEqualsCanonicalizing([$this->milk->id, $this->soy->id], $logs->pluck('auditable_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['product' => 'MILK-1 Oat milk', 'pack' => 'Carton'], $logs[0]->old_values);
        $this->assertSame(['product' => 'SOY-1 Soy milk', 'pack' => 'Each'], $logs[0]->new_values);
        $this->assertSame(['barcode' => self::GOOD], $logs[0]->meta);
        $this->assertSame($this->manager->id, $logs[0]->user_id);

        // Moving onto the pack it's already on is refused.
        $this->actingAs($this->manager)->post(route('barcode-capture.move'), ['barcode_id' => $b->id, 'product_unit_id' => $soyEach->id])->assertSessionHasErrors('barcode');
    }

    public function test_barcode_lookup_finds_the_pack_and_moves_or_removes()
    {
        $b = $this->barcodeOn($this->unit($this->milk, 1), self::GOOD);

        $this->actingAs($this->manager)->get(route('barcodes.index', ['code' => self::GOOD]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('products/BarcodeLookup')
                ->where('match.product.sku', 'MILK-1')->where('match.unit.name', 'Each')->where('match.last_on_pack', true)->where('can.manage', true));
        $this->actingAs($this->manager)->get(route('barcodes.index', ['code' => self::OTHER]))->assertInertia(fn (AssertableInertia $p) => $p->where('match', null));

        $this->actingAs($this->manager)->post(route('barcodes.move', $b), ['product_unit_id' => $this->unit($this->milk, 12)->id])->assertSessionHasNoErrors();
        $this->assertSame($this->unit($this->milk, 12)->id, $b->fresh()->product_unit_id);
        $this->assertSame(1, AuditLog::where('event', 'barcode_moved')->count()); // same product: one entry

        $this->actingAs($this->manager)->delete(route('barcodes.destroy', $b))->assertSessionHasNoErrors();
        $this->assertSame(0, Barcode::count());
    }

    public function test_only_managers_and_admins_can_change_remove_or_move()
    {
        $b = $this->barcodeOn($this->unit($this->milk, 12), self::GOOD);
        $soyEach = $this->unit($this->soy, 1);

        foreach ([Permissions::ROLE_WAREHOUSE_STAFF, Permissions::ROLE_PURCHASING, Permissions::ROLE_READ_ONLY] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->put(route('barcodes.update', $b), ['barcode' => self::THIRD])->assertForbidden();
            $this->actingAs($user)->delete(route('barcodes.destroy', $b))->assertForbidden();
            $this->actingAs($user)->post(route('barcodes.move', $b), ['product_unit_id' => $soyEach->id])->assertForbidden();
            $this->actingAs($user)->post(route('barcode-capture.move'), ['barcode_id' => $b->id, 'product_unit_id' => $soyEach->id])->assertForbidden();
        }
        // Staff can still look a barcode up, without the actions.
        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))->get(route('barcodes.index', ['code' => self::GOOD]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('match.id', $b->id)->where('can.manage', false));

        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);
        $this->actingAs($admin)->post(route('barcodes.move', $b), ['product_unit_id' => $soyEach->id])->assertSessionHasNoErrors();
        $this->assertSame($soyEach->id, $b->fresh()->product_unit_id);
        $this->assertSame(0, AuditLog::where('event', 'barcode_changed')->count());
    }
}
