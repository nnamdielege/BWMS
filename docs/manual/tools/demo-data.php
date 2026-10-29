<?php

/**
 * Demo data for the user manual screenshots. FAKE data only: invented cafés,
 * suppliers, products and people; example.com emails.
 *
 *   APP_ENV=manual php artisan migrate:fresh --seed --force   (database must end in _test)
 *   APP_ENV=manual php docs/manual/tools/demo-data.php
 *
 * Everything goes through the real services (ledger, receiving, orders,
 * pick & pack), so the screens show states the app really produces.
 * Prints the admin TOTP secret used by capture.mjs.
 */

use App\Enums\MovementType;
use App\Enums\OrderChannel;
use App\Models\Batch;
use App\Models\Category;
use App\Models\ChannelListing;
use App\Models\ChannelUnmappedVariant;
use App\Models\Customer;
use App\Models\DeliveryRun;
use App\Models\IntegrationInbox;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Range;
use App\Models\ReasonCode;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SyncAttempt;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\Orders\ChannelOrderData;
use App\Services\Orders\OrderService;
use App\Services\Orders\PickPackService;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Purchasing\ReceivingService;
use App\Services\Stock\CountTaskService;
use App\Services\Stock\MovementDraft;
use App\Services\Stock\StockLedger;
use App\Services\Stock\StocktakeService;
use App\Support\DatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DatabaseSafety::assertDestructiveAllowed('Seeding manual demo data');
if (Product::exists()) {
    fwrite(STDERR, "Products already exist. Run migrate:fresh --seed on the demo database first.\n");
    exit(1);
}

const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';
$tz = config('bwms.business_timezone');
$today = Carbon::now($tz)->startOfDay();
$d = fn (int $days) => $today->copy()->addDays($days)->toDateString();

// --- People -----------------------------------------------------------------
$person = function (string $name, string $email, string $role, bool $twoFactor = false): User {
    $u = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('demo-password-1')]);
    $u->forceFill(['is_active' => true, 'email_verified_at' => now(), 'last_login_at' => now()->subHours(rand(1, 40))])->save();
    $u->assignRole($role);
    if ($twoFactor) {
        $u->forceFill([
            'two_factor_secret' => encrypt(TOTP_SECRET),
            'two_factor_recovery_codes' => encrypt(json_encode(collect(range(1, 8))->map(fn () => Str::random(10).'-'.Str::random(10))->all())),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    return $u;
};
$admin = $person('Alex Admin', 'alex.admin@example.com', 'admin', true);
$manager = $person('Morgan Manager', 'morgan.manager@example.com', 'warehouse_manager');
$picker = $person('Sam Picker', 'sam.picker@example.com', 'warehouse_staff');
$person('Pat Purchasing', 'pat.purchasing@example.com', 'purchasing');
$person('Riley Viewer', 'riley.viewer@example.com', 'read_only');
$person('Casey Picker', 'casey.picker@example.com', 'warehouse_staff');
Auth::setUser($admin);
UserInvitation::issue('jordan.new@example.com', 'Jordan New', 'warehouse_staff', $admin);

Setting::put([
    'company.name' => 'Local Selection', 'company.abn' => '00 000 000 000 (demo)',
    'company.address' => "1 Example Street\nClontarf QLD 4019", 'company.phone' => '07 0000 0000',
    'company.email' => 'orders@example.com', 'company.delivery_address' => "Clontarf Warehouse\n1 Example Street, Clontarf QLD 4019",
    'po.terms' => 'Please quote the PO number on your delivery docket and invoice. Deliveries Mon–Fri 6 am – 2 pm.',
]);

// --- Locations ----------------------------------------------------------------
$site = Location::where('type', Location::TYPE_SITE)->firstOrFail();
$stockBin = Location::where('parent_id', $site->id)->where('code', 'STOCK')->firstOrFail();
$stockBin->update(['walk_seq' => 90, 'name' => 'General stock (overflow)']);
Location::where('parent_id', $site->id)->where('code', 'QUARANTINE')->update(['walk_seq' => 99]);
$zone = fn (string $code, string $name) => Location::create(['parent_id' => $site->id, 'type' => 'zone', 'code' => $code, 'name' => $name, 'is_sellable' => false]);
$amb = $zone('AMB', 'Ambient racking');
$cool = $zone('COOL', 'Cool room');
$bin = fn (Location $z, string $code, string $name, int $walk) => Location::create(['parent_id' => $z->id, 'type' => 'bin', 'code' => $code, 'name' => $name, 'walk_seq' => $walk]);
$b = [
    'A11' => $bin($amb, 'A-01-1', 'Aisle 1 bay 1 low', 10),
    'A12' => $bin($amb, 'A-01-2', 'Aisle 1 bay 1 high', 11),
    'A21' => $bin($amb, 'A-02-1', 'Aisle 2 bay 1 low', 20),
    'A22' => $bin($amb, 'A-02-2', 'Aisle 2 bay 1 high', 21),
    'C11' => $bin($cool, 'C-01-1', 'Cool room shelf 1', 50),
    'C12' => $bin($cool, 'C-01-2', 'Cool room shelf 2', 51),
];

// --- Catalogue -----------------------------------------------------------------
$cat = fn (string $n) => Category::create(['name' => $n]);
$cats = ['coffee' => $cat('Coffee'), 'milk' => $cat('Milk'), 'tea' => $cat('Tea'), 'kombucha' => $cat('Kombucha'), 'juice' => $cat('Juice'), 'packaging' => $cat('Packaging'), 'syrup' => $cat('Syrups')];
$coldBrew = Range::create(['name' => 'Harbour Cold Brew']);
$komb = Range::create(['name' => 'Bay Kombucha']);

$product = function (array $p, array $packs) {
    $prod = Product::create($p);
    foreach ($packs as $i => [$name, $qty, $barcodes, $purchase, $sale]) {
        $u = $prod->units()->create(['name' => $name, 'base_qty' => $qty, 'is_base' => $qty === 1, 'purchasable' => true, 'sellable' => true,
            'default_purchase' => $purchase, 'default_sale' => $sale]);
        foreach ($barcodes as $code) {
            $u->barcodes()->create(['barcode' => $code, 'type' => strlen($code) === 13 ? 'ean13' : 'other']);
        }
    }

    return $prod->load('units');
};
$P = [];
$P['cbo'] = $product(['sku' => 'HCB-ORIG-250', 'name' => 'Harbour Cold Brew Original 250 ml', 'category_id' => $cats['coffee']->id, 'range_id' => $coldBrew->id, 'base_unit' => 'can',
    'is_batch_tracked' => true, 'shelf_life_days' => 180, 'reorder_point' => 96, 'reorder_qty' => 240, 'sell_price' => '3.20', 'sell_channels' => 'both'],
    [['Can', 1, ['9300000000017'], false, false], ['Carton', 24, ['19300000000014'], true, true]]);
$P['cbv'] = $product(['sku' => 'HCB-VAN-250', 'name' => 'Harbour Cold Brew Vanilla 250 ml', 'category_id' => $cats['coffee']->id, 'range_id' => $coldBrew->id, 'base_unit' => 'can',
    'is_batch_tracked' => true, 'shelf_life_days' => 180, 'reorder_point' => 48, 'reorder_qty' => 120, 'sell_price' => '3.40', 'sell_channels' => 'ordermentum'],
    [['Can', 1, ['9300000000024'], false, false], ['Carton', 24, ['19300000000021'], true, true]]);
$P['oat'] = $product(['sku' => 'NOM-OAT-1L', 'name' => 'Northside Oat Milk 1 L', 'category_id' => $cats['milk']->id, 'base_unit' => 'bottle', 'is_chilled' => true,
    'is_batch_tracked' => true, 'shelf_life_days' => 21, 'short_dated_days' => 5, 'reorder_point' => 36, 'reorder_qty' => 72, 'sell_price' => '3.90', 'sell_channels' => 'both'],
    [['Bottle', 1, ['9300000000031'], false, false], ['Crate', 6, ['19300000000038'], true, true]]);
$P['full'] = $product(['sku' => 'BFC-FULL-2L', 'name' => 'Bayside Full Cream Milk 2 L', 'category_id' => $cats['milk']->id, 'base_unit' => 'bottle', 'is_chilled' => true,
    'is_batch_tracked' => true, 'shelf_life_days' => 12, 'reorder_point' => 24, 'reorder_qty' => 48, 'sell_price' => '4.10', 'sell_channels' => 'ordermentum'],
    [['Bottle', 1, ['9300000000048'], false, false], ['Crate', 9, ['19300000000045'], true, true]]);
$P['kgin'] = $product(['sku' => 'BAYK-GIN-330', 'name' => 'Bay Kombucha Ginger 330 ml', 'category_id' => $cats['kombucha']->id, 'range_id' => $komb->id, 'base_unit' => 'bottle', 'is_chilled' => true,
    'is_batch_tracked' => true, 'shelf_life_days' => 90, 'reorder_point' => 24, 'reorder_qty' => 48, 'sell_price' => '3.60', 'sell_channels' => 'both'],
    [['Bottle', 1, ['9300000000055'], false, false], ['Case', 12, ['19300000000052'], true, true]]);
$P['tea'] = $product(['sku' => 'LST-EB-100', 'name' => 'English Breakfast Tea 100 bags', 'category_id' => $cats['tea']->id, 'base_unit' => 'box',
    'reorder_point' => 10, 'reorder_qty' => 20, 'sell_price' => '9.50', 'sell_channels' => 'portal'],
    [['Box', 1, ['9300000000062'], false, true], ['Carton', 10, [], true, false]]);
$P['cup'] = $product(['sku' => 'CUP-8OZ-50', 'name' => 'Compostable Cup 8 oz (sleeve of 50)', 'category_id' => $cats['packaging']->id, 'base_unit' => 'sleeve',
    'reorder_point' => 40, 'reorder_qty' => 100, 'sell_price' => '6.80', 'sell_channels' => 'both'],
    [['Sleeve', 1, ['9300000000079'], false, true], ['Carton', 20, ['19300000000076'], true, false]]);
$P['syr'] = $product(['sku' => 'SYR-CAR-750', 'name' => 'Caramel Syrup 750 ml', 'category_id' => $cats['syrup']->id, 'base_unit' => 'bottle',
    'reorder_point' => 6, 'reorder_qty' => 12, 'sell_price' => '11.00', 'sell_channels' => 'ordermentum'],
    [['Bottle', 1, ['9300000000086'], true, true]]);
$P['oj'] = $product(['sku' => 'CPJ-OJ-1L', 'name' => 'Cold-pressed Orange Juice 1 L', 'category_id' => $cats['juice']->id, 'base_unit' => 'bottle', 'is_chilled' => true,
    'is_batch_tracked' => true, 'shelf_life_days' => 14, 'sell_price' => '6.50', 'sell_channels' => 'ordermentum'],
    [['Bottle', 1, ['9300000000093'], true, true]]);
$P['del'] = $product(['sku' => 'SVC-DELIVERY', 'name' => 'Delivery fee', 'base_unit' => 'each', 'is_stock_tracked' => false, 'sell_channels' => 'ordermentum', 'sell_price' => '15.00'],
    [['Each', 1, [], false, true]]);
$unit = fn (string $k, int $qty) => $P[$k]->units->firstWhere('base_qty', $qty);

// --- Suppliers and purchase orders ------------------------------------------------
$sup = fn (string $code, string $name, string $contact, int $lead) => Supplier::create(['code' => $code, 'name' => $name, 'contact_name' => $contact,
    'email' => strtolower($code).'@example.com', 'phone' => '07 0000 00'.rand(10, 99), 'payment_terms' => '30 days EOM', 'lead_time_days' => $lead,
    'address' => "Unit 2, 10 Sample Road\nBrisbane QLD 4000", 'is_active' => true]);
$roaster = $sup('HARBOUR', 'Harbour Roasters (demo)', 'Jamie Roaster', 3);
$dairy = $sup('NORTHDAIRY', 'Northside Dairy Co (demo)', 'Taylor Dairy', 1);
$pack = $sup('BAYPACK', 'Bayside Packaging (demo)', 'Robin Packer', 5);

$pos = app(PurchaseOrderService::class);
$recv = app(ReceivingService::class);
$po = function (Supplier $s, array $lines, int $orderedDaysAgo, ?int $expectedIn, User $by) use ($pos, $site, $d) {
    return $pos->saveDraft(null, ['supplier_id' => $s->id, 'site_id' => $site->id, 'order_date' => $d(-$orderedDaysAgo),
        'expected_date' => $expectedIn === null ? null : $d($expectedIn), 'notes' => null,
        'lines' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'product_unit_id' => $l[1]->id, 'qty_ordered' => $l[2], 'unit_cost' => $l[3], 'tax_rate' => 10], $lines)], $by);
};
$line = fn (PurchaseOrder $o, Product $p) => $o->lines()->where('product_id', $p->id)->firstOrFail();
$rl = fn (PurchaseOrder $o, Product $p, int $acc, Location $to, ?string $lot = null, ?string $bb = null, int $dmg = 0, int $rej = 0, ?string $reason = null, ?string $note = null) => [
    'purchase_order_line_id' => $line($o, $p)->id, 'qty_accepted_base' => $acc, 'qty_damaged_base' => $dmg, 'qty_rejected_base' => $rej,
    'location_id' => $to->id, 'lot_code' => $lot, 'best_before' => $bb, 'discrepancy_reason' => $reason, 'note' => $note];

// PO 1: received in full (cold brew, tea, cups, syrup)
$po1 = $po($roaster, [[$P['cbo'], $unit('cbo', 24), 10, '52.80'], [$P['cbv'], $unit('cbv', 24), 6, '55.20'], [$P['tea'], $unit('tea', 10), 3, '61.00'], [$P['syr'], $unit('syr', 1), 12, '7.20']], 12, -8, $manager);
$pos->markSent($po1, $manager);
Auth::setUser($picker);
$recv->receive($po1, $picker, [
    $rl($po1, $P['cbo'], 144, $b['A11'], 'HCB2610A', $d(160)), $rl($po1, $P['cbo'], 96, $b['A12'], 'HCB2611B', $d(175)),
    $rl($po1, $P['cbv'], 144, $b['A12'], 'HCV2610A', $d(150)),
    $rl($po1, $P['tea'], 30, $b['A21']), $rl($po1, $P['syr'], 12, $b['A22']),
], (string) Str::uuid(), 'DKT-10442');

// PO 2: chilled, received with a damaged crate and a rejected case -> partially received
$po2 = $po($dairy, [[$P['oat'], $unit('oat', 6), 12, '15.60'], [$P['full'], $unit('full', 9), 6, '27.00'], [$P['kgin'], $unit('kgin', 12), 4, '30.00'], [$P['oj'], $unit('oj', 1), 24, '0']], 4, -1, $manager);
$pos->markSent($po2, $manager);
$recv->receive($po2, $picker, [
    $rl($po2, $P['oat'], 60, $b['C11'], 'NOM-1027', $d(18), dmg: 6, note: 'Crate crushed in transit'),
    $rl($po2, $P['full'], 54, $b['C11'], 'BFC-1026', $d(9)),
    $rl($po2, $P['kgin'], 36, $b['C12'], 'BK-0915', $d(80), rej: 12, reason: 'wrong_item', note: 'Lemon sent instead of ginger'),
], (string) Str::uuid(), 'ND-55120');
// OJ came on a separate docket, already short-dated
$recv->receive($po2, $picker, [$rl($po2, $P['oj'], 12, $b['C12'], 'CPJ-1020', $d(-1))], (string) Str::uuid(), 'ND-55131');

// PO 3: sent and overdue (dashboard tile); PO 4: draft
Auth::setUser($manager);
$po3 = $po($pack, [[$P['cup'], $unit('cup', 20), 5, '96.00']], 9, -2, $manager);
$pos->markSent($po3, $manager);
$po($roaster, [[$P['cbo'], $unit('cbo', 24), 8, '52.80'], [$P['cbv'], $unit('cbv', 24), 4, '55.20']], 0, 4, $manager);

// Cups arrived on an earlier PO that was closed short
$po5 = $po($pack, [[$P['cup'], $unit('cup', 20), 6, '96.00']], 30, -22, $manager);
$pos->markSent($po5, $manager);
Auth::setUser($picker);
$recv->receive($po5, $picker, [$rl($po5, $P['cup'], 80, $b['A22'])], (string) Str::uuid(), 'BP-7781');
$pos->closeShort($po5->refresh(), $manager, 'Supplier out of stock; remaining 2 cartons cancelled');

// --- Customers ----------------------------------------------------------------------
$cust = fn (string $code, string $name, string $street, string $suburb, string $pc, array $extra = []) => Customer::create($extra + [
    'code' => $code, 'name' => $name, 'contact_name' => 'Owner', 'email' => strtolower($code).'@example.com', 'phone' => '0400 000 000',
    'delivery_street' => $street, 'delivery_suburb' => $suburb, 'delivery_postcode' => $pc, 'is_active' => true]);
$C = [
    'beach' => $cust('C-BEACH', 'Pelican Beach Café (demo)', '5 Example Esplanade', 'Clontarf', '4019', ['ordermentum_retailer_id' => '00000000-0000-4000-8000-000000000001']),
    'redcl' => $cust('C-REDCL', 'Jetty Espresso Bar (demo)', '12 Sample Parade', 'Redcliffe', '4020', ['portal_user_id' => 9001]),
    'gold' => $cust('C-GOLD', 'Surfside Coffee Co (demo)', '3 Demo Highway', 'Surfers Paradise', '4217'),
    'west' => $cust('C-WEST', 'Riverbend Brew House (demo)', '40 Test Street', 'West End', '4101', ['ordermentum_retailer_id' => '00000000-0000-4000-8000-000000000002']),
    'sunny' => $cust('C-SUNNY', 'Hinterland Roasters (demo)', '8 Placeholder Lane', 'Nambour', '4560'),
    'far' => $cust('C-FAR', 'Far North Kiosk (demo)', '1 Nowhere Road', 'Cairns', '4870'),
];

// --- Orders -------------------------------------------------------------------------
$orders = app(OrderService::class);
$manual = function (Customer $c, array $lines, array $extra = [], bool $allowShort = false) use ($orders, $manager, $site) {
    return $orders->createManual($manager, $extra + ['customer_id' => $c->id, 'site_id' => $site->id, 'short_ship_policy' => 'backorder',
        'lines' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'product_unit_id' => $l[1]->id, 'qty' => $l[2], 'unit_price' => $l[3] ?? null], $lines)], $allowShort);
};
$channel = function (OrderChannel $ch, string $ref, Customer $c, array $lines) use ($orders, $site) {
    return $orders->upsertFromChannel(new ChannelOrderData($ch, $ref, 1, 'confirmed', $site->id,
        array_map(fn ($l, $i) => ['line_ref' => (string) ($i + 1), 'product_id' => $l[0]->id, 'product_unit_id' => $l[1]->id, 'qty' => $l[2], 'unit_price' => $l[3] ?? null], $lines, array_keys($lines)),
        $c->id, null, null, ['street' => $c->delivery_street, 'suburb' => $c->delivery_suburb, 'postcode' => $c->delivery_postcode]));
};
Auth::setUser($manager);
// Zone 1 (Clontarf / Redcliffe) next run: three orders for the pick & pack walkthrough
$o1 = $channel(OrderChannel::Ordermentum, 'OM-10023', $C['beach'], [[$P['cbo'], $unit('cbo', 24), 2, '76.80'], [$P['oat'], $unit('oat', 6), 3, '23.40'], [$P['tea'], $unit('tea', 1), 4, '9.50'], [$P['del'], $unit('del', 1), 1, '15.00']]);
$o2 = $channel(OrderChannel::Portal, 'P-55012', $C['redcl'], [[$P['kgin'], $unit('kgin', 12), 1, '43.20'], [$P['cup'], $unit('cup', 1), 6, '6.80'], [$P['full'], $unit('full', 9), 1, '36.90']]);
$o3 = $manual($C['beach'], [[$P['syr'], $unit('syr', 1), 2, '11.00'], [$P['cbv'], $unit('cbv', 24), 1, '81.60']]);
// Other runs waiting to pick
$manual($C['gold'], [[$P['cbo'], $unit('cbo', 24), 1, '76.80'], [$P['cup'], $unit('cup', 1), 10, '6.80']]);
$channel(OrderChannel::Ordermentum, 'OM-10031', $C['west'], [[$P['oat'], $unit('oat', 6), 2, '23.40'], [$P['tea'], $unit('tea', 1), 2, '9.50']]);
$manual($C['sunny'], [[$P['kgin'], $unit('kgin', 12), 1, '43.20']], ['requested_delivery_date' => null]);
// No delivery area -> "Orders without a run"
$manual($C['far'], [[$P['syr'], $unit('syr', 1), 1, '11.00']]);
// Oversold: accepted beyond available stock
$manual($C['west'], [[$P['full'], $unit('full', 9), 8, '36.90']], ['notes' => 'Weekly standing order'], true);

// --- Pick & pack in progress on the Zone 1 run ----------------------------------------
$pp = app(PickPackService::class);
$run = DeliveryRun::findOrFail($o1->refresh()->delivery_run_id);
Auth::setUser($picker);
$list = $pp->startNew($run, $picker);
$lines = $pp->walkOrder($list);
foreach ($lines as $i => $pl) {
    $pl->loadMissing(['location', 'product.units.barcodes']);
    if ($pl->product->sku === 'BFC-FULL-2L') {
        // Short pick: only 6 of 9 found -> count task on the bin
        $pp->recordLine($pl, $picker, 6, $pl->location->barcode, [], 'label_damaged');

        continue;
    }
    if ($i >= 3) {
        continue; // leave the rest for the "picking" screenshot
    }
    $pp->recordLine($pl, $picker, $pl->qty_to_pick_base, $pl->location->barcode, [], 'scanner_fault');
}

// A second list on another run, fully picked and one order packed
$gold = Order::where('customer_id', $C['gold']->id)->firstOrFail();
$goldRun = DeliveryRun::findOrFail($gold->delivery_run_id);
Auth::setUser(User::where('email', 'casey.picker@example.com')->first());
$list2 = $pp->startNew($goldRun, Auth::user());
foreach ($pp->walkOrder($list2) as $pl) {
    $pl->loadMissing('location');
    $pp->recordLine($pl, Auth::user(), $pl->qty_to_pick_base, $pl->location->barcode, [], 'scanner_fault');
}
$pp->pack($list2->refresh(), $gold, Auth::user(), 2, $list2->lines()->where('order_line_id', '>', 0)->whereIn('order_line_id', $gold->lines()->pluck('id'))->pluck('id')->all());

// --- Stock oddities: a negative bin and an expired batch already in stock ---------------
Auth::setUser($manager);
$ledger = app(StockLedger::class);
$ledger->post([new MovementDraft($P['syr'], $b['A21'], -2, MovementType::Sale, 'demo:neg:1', note: 'Picked from the wrong bin (demo)', allowNegative: true)], $manager);
app(CountTaskService::class)->raise($b['A21'], $P['syr'], 'negative_after_dispatch', null, null, -2);
$found = ReasonCode::where('code', 'FOUND')->first();
$ledger->post([new MovementDraft($P['cbo'], $stockBin, 12, MovementType::Adjustment, 'demo:found:1', Batch::findOrCreateFor($P['cbo'], 'HCB2608Z', $d(-3)), $found, note: 'Half carton found behind the racking')], $manager);

// --- Stocktakes: one posted, one counting -----------------------------------------------
$st = app(StocktakeService::class);
$posted = $st->create($manager, $b['A22'], 'Monthly cycle count: aisle 2 high');
foreach ($posted->lines()->get() as $sl) {
    $st->recordCount($sl, max(0, $sl->snapshot_qty - ($sl->snapshot_qty > 50 ? 1 : 0)), $manager);
}
$st->post($posted->refresh(), $manager);
$counting = $st->create($manager, $cool, 'Cool room weekly count');
foreach ($counting->lines()->get()->take(2) as $sl) {
    $st->recordCount($sl, $sl->snapshot_qty, $picker);
}

// --- Channel sync (switches stay off; these rows show what the screen looks like) --------
$listing = function (string $channel, Product $p, int $qty, array $state = []) {
    $packQty = $p->units->firstWhere('default_sale', true)?->base_qty ?? 1;
    $l = ChannelListing::create(['channel' => $channel, 'product_id' => $p->id, 'product_unit_id' => $p->units->firstWhere('base_qty', $packQty)->id,
        'external_product_id' => $channel === 'ordermentum' ? (string) Str::uuid() : null,
        'external_variant_id' => $channel === 'ordermentum' ? (string) Str::uuid() : (string) rand(1000, 9999), 'external_sku' => $p->sku, 'sync_enabled' => $state['enabled'] ?? true]);
    $l->forceFill(array_diff_key($state, ['enabled' => 1]) + ['last_pushed_qty' => $qty, 'last_pushed_at' => now()->subMinutes(rand(5, 300)), 'last_response_code' => 200])->save();

    return $l;
};
$listing('ordermentum', $P['cbo'], 13);
$listing('ordermentum', $P['cbv'], 5);
$listing('ordermentum', $P['oat'], 8);
$listing('ordermentum', $P['full'], 0, ['last_error' => '422 Unprocessable Entity: quantity must be an integer (demo)', 'last_response_code' => 422]);
$listing('ordermentum', $P['kgin'], 2, ['last_remote_qty' => 4, 'last_checked_at' => now()->subHours(9)]);
$listing('ordermentum', $P['syr'], 9, ['remote_status' => 'untracked']);
$listing('ordermentum', $P['oj'], 0, ['enabled' => false, 'last_pushed_at' => null, 'last_pushed_qty' => null, 'last_response_code' => null]);
$listing('portal', $P['tea'], 22);
$listing('portal', $P['cup'], 74);
$listing('portal', $P['kgin'], 2, ['enabled' => false]);
// The two failed pushes behind the BFC-FULL-2L push failure (dashboard "Sync failures" tile)
foreach ([2, 1] as $retry) {
    SyncAttempt::create(['channel' => 'ordermentum', 'operation' => 'push', 'product_id' => $P['full']->id, 'response_code' => 422,
        'success' => false, 'error' => 'quantity must be an integer (demo)', 'created_at' => now()->subHours($retry)]);
}
ChannelUnmappedVariant::create(['channel' => 'ordermentum', 'external_variant_id' => (string) Str::uuid(), 'external_sku' => 'HCB-MOCHA-250',
    'name' => 'Harbour Cold Brew Mocha 250 ml', 'occurrences' => 2]);
$inbox = function (string $event, string $ext, string $status, ?string $error = null, ?string $sig = 'valid_hex', int $attempts = 1) {
    IntegrationInbox::create(['channel' => 'ordermentum', 'event' => $event, 'external_id' => $ext, 'dedupe_key' => hash('sha256', $event.$ext.$status.microtime()),
        'status' => $status, 'payload' => '{"demo":true}', 'attempts' => $attempts, 'error' => $error, 'signature_status' => $sig,
        'processed_at' => $status === 'processed' ? now() : null]);
};
$inbox('order_created', 'OM-10023', 'processed');
$inbox('order_created', 'OM-10031', 'processed');
$inbox('order_updated', 'OM-10040', 'held', 'Ingestion is switched off (ORDERMENTUM_INGEST_ENABLED).');
$inbox('order_created', 'OM-10044', 'needs_mapping', 'Unmapped variant HCB-MOCHA-250', attempts: 2);
$inbox('order_created', 'OM-10045', 'failed', 'Order fetch timed out after 15s (demo)', attempts: 3);

echo "Demo data ready.\n";
echo 'Admin: alex.admin@example.com / demo-password-1, TOTP secret '.TOTP_SECRET.PHP_EOL;
echo "Zone 1 pick list {$list->number} (Sam), packed list {$list2->number} (Casey). Run {$run->id} / {$goldRun->id}\n";
