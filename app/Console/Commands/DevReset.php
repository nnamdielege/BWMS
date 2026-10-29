<?php

namespace App\Console\Commands;

use App\Authorization\Permissions;
use App\Integrations\Ordermentum\LocalOrderFixtures;
use App\Models\ChannelListing;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\User;
use App\Services\Catalogue\PackSetupService;
use App\Support\DatabaseSafety;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Local test data in one go (docs/TESTING.md). Rebuilds the development
 * database from the migrations and seeders, imports the real products,
 * suppliers and customers from the local legacy copy (bwms_legacy_prod), sets
 * default packs, adds pick bins and portal test listings, and posts a fake
 * opening stocktake. Your user accounts (passwords, 2FA, roles) are kept.
 *
 * Only for APP_ENV=local on a database listed in BWMS_DEV_RESET_DATABASES
 * (DatabaseSafety::devResetRefusal). Never touches Ordermentum or the portal.
 */
class DevReset extends Command
{
    protected $signature = 'bwms:dev-reset
        {--force : Skip the confirmation prompt}
        {--packs=20 : Opening stock per product, in its default sale pack}
        {--skip-legacy : Only migrate and seed (no legacy import, packs or stock)}';

    protected $description = 'Rebuild the local dev database with real catalogue data and fake opening stock (local only)';

    /** Pick bins added to the default site: code => [name, walk order, chilled]. */
    private const BINS = [
        'A-01' => ['Aisle A, bay 1', 10, false],
        'A-02' => ['Aisle A, bay 2', 20, false],
        'A-03' => ['Aisle A, bay 3', 30, false],
        'A-04' => ['Aisle A, bay 4', 40, false],
        'COOL-01' => ['Cool room shelf 1', 50, true],
    ];

    /** Test assumptions, not real product data: names that get chilled and/or batch tracking. */
    private const CHILLED = '/kombucha|boocha|juice|smoothie/i';

    private const BATCH_ONLY = '/milk/i';

    public function handle(PackSetupService $packs, LocalOrderFixtures $fixtures): int
    {
        if ($reason = DatabaseSafety::devResetRefusal()) {
            $this->error("Refusing bwms:dev-reset. {$reason}");

            return self::FAILURE;
        }
        $database = DatabaseSafety::databaseName();
        if (! $this->option('force') && ! $this->confirm("This wipes the local database \"{$database}\" and rebuilds it. Continue?")) {
            return self::FAILURE;
        }

        $users = $this->rememberUsers();

        $this->components->task("Rebuilding {$database} (migrate:fresh --seed)", function () {
            DB::prohibitDestructiveCommands(false); // devResetRefusal() passed above
            $this->callSilently('migrate:fresh', ['--seed' => true, '--force' => true]) === 0 || throw new \RuntimeException('migrate:fresh failed.');
        });
        $accounts = $this->restoreUsers($users);
        $fixtures->clear();

        if ($this->option('skip-legacy')) {
            return $this->finish($accounts);
        }

        $this->line('Importing products, suppliers and customers from the local legacy copy…');
        if ($this->call('bwms:migrate-legacy', ['--commit' => true]) !== 0) {
            $this->error('The legacy import failed (see above). Check LEGACY_DB_* in .env and that bwms_legacy_prod exists.');

            return self::FAILURE;
        }

        $actor = User::role(Permissions::ROLE_WAREHOUSE_MANAGER)->first() ?? User::first();
        $this->components->task('Default packs (case size from the product name)', fn () => $this->setPacks($packs, $actor));
        $this->components->task('Chilled and batch-tracked flags (test assumptions)', fn () => $this->setFlags());
        $this->components->task('Pick bins '.implode(', ', array_keys(self::BINS)), fn () => $this->addBins());
        $this->components->task('Portal test listings (portal product ID = 10000 + product ID)', fn () => $this->addPortalListings());

        $csv = $this->openingCsv((int) $this->option('packs'));
        $this->line('Posting the fake opening stocktake…');
        $cutover = now(config('bwms.business_timezone'))->format('Y-m-d H:i');
        if ($this->call('bwms:migrate-legacy', ['--commit' => true, '--opening-balances' => $csv, '--cutover' => $cutover]) !== 0) {
            $this->error('The opening stocktake failed (see above).');

            return self::FAILURE;
        }
        $this->callSilently('stock:verify');

        return $this->finish($accounts);
    }

    /** @return array{users: Collection, roles: Collection} */
    private function rememberUsers(): array
    {
        if (! Schema::hasTable('users')) {
            return ['users' => collect(), 'roles' => collect()];
        }

        return [
            'users' => DB::table('users')->get()->map(fn ($u) => (array) $u),
            'roles' => Schema::hasTable('model_has_roles')
                ? DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->where('model_type', (new User)->getMorphClass())->get(['model_id', 'roles.name'])
                : collect(),
        ];
    }

    /**
     * Puts your accounts back as they were (same IDs, passwords, 2FA), and adds a
     * local manager and picker the first time.
     *
     * @return list<array{0: string, 1: string, 2: string}> email, role, password (new accounts only)
     */
    private function restoreUsers(array $saved): array
    {
        $columns = Schema::getColumnListing('users');
        foreach ($saved['users'] as $row) {
            DB::table('users')->insert(array_intersect_key($row, array_flip($columns)));
        }
        foreach ($saved['roles'] as $role) {
            User::find($role->model_id)?->assignRole($role->name);
        }

        $created = [];
        foreach (['manager@local.test' => ['Local Manager', Permissions::ROLE_WAREHOUSE_MANAGER], 'picker@local.test' => ['Local Picker', Permissions::ROLE_WAREHOUSE_STAFF]] as $email => [$name, $role]) {
            if (User::where('email', $email)->exists()) {
                continue;
            }
            $password = Str::password(16, symbols: false);
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill(['is_active' => true, 'email_verified_at' => now()])->save();
            $user->assignRole($role);
            $created[] = [$email, $role, $password];
        }

        return $created;
    }

    private function setPacks(PackSetupService $packs, User $actor): void
    {
        $rows = [['sku', 'base_unit', 'pack_name', 'qty_per_pack', 'legacy_unit']];
        foreach (PackSetupService::defaultPackProducts()->get() as $product) {
            if ($size = $this->caseSize($product->name)) {
                $rows[] = [$product->sku, 'each', 'Each', 1, ''];
                $rows[] = [$product->sku, 'each', 'Case', $size, 'yes'];
            }
        }
        if (count($rows) === 1) {
            return;
        }

        $path = $this->writeCsv('packs.csv', $rows);
        $preview = $packs->preview($path);
        foreach ($preview['errors'] as $error) {
            $this->warn("  pack setup: {$error}");
        }
        $packs->apply($preview['plans'], $actor);
    }

    /** "x 12", "12x260ml", "(500ml X 24)", "x6" -> case size; null when the name gives none. */
    private function caseSize(string $name): ?int
    {
        if (preg_match('/\b(\d{1,2})\s*x\s*\d/i', $name, $m) || preg_match('/\bx\s*(\d{1,2})\b/i', $name, $m)) {
            $size = (int) $m[1];

            return $size >= 2 && $size <= 60 ? $size : null;
        }

        return null;
    }

    private function setFlags(): void
    {
        Product::where('is_stock_tracked', true)->get()->each(function (Product $p) {
            $chilled = (bool) preg_match(self::CHILLED, $p->name);
            if ($chilled || preg_match(self::BATCH_ONLY, $p->name)) {
                $p->update(['is_chilled' => $chilled, 'is_batch_tracked' => true, 'shelf_life_days' => $p->shelf_life_days ?? 60]);
            }
        });
    }

    private function addBins(): void
    {
        $site = Location::where('type', Location::TYPE_SITE)->where('code', config('bwms.default_site.code'))->firstOrFail();
        foreach (self::BINS as $code => [$name, $walk]) {
            Location::firstOrCreate(['parent_id' => $site->id, 'code' => $code], ['type' => Location::TYPE_BIN, 'name' => $name, 'walk_seq' => $walk]);
        }
        Location::where('parent_id', $site->id)->where('code', 'STOCK')->update(['walk_seq' => 90]);
    }

    private function addPortalListings(): void
    {
        Product::with('units')->where('is_active', true)->where('is_stock_tracked', true)->get()->each(function (Product $p) {
            $unit = $p->units->firstWhere('default_sale', true) ?? $p->units->sortBy('base_qty')->first();
            $portalId = (string) (10000 + $p->id);
            ChannelListing::firstOrCreate(['channel' => 'portal', 'external_variant_id' => $portalId], [
                'product_id' => $p->id, 'product_unit_id' => $unit->id, 'external_product_id' => $portalId, 'external_sku' => $p->sku, 'sync_enabled' => false,
            ]);
            $p->addSellChannel('portal');
        });
    }

    /**
     * N of each product's default sale pack, spread over the pick bins (chilled in
     * the cool room). One product at zero, three batch-tracked ones with a
     * best-before in 1-3 days, the rest 60 days out.
     */
    private function openingCsv(int $packsEach): string
    {
        $ambient = collect(self::BINS)->reject(fn ($b) => $b[2])->keys()->values();
        $cool = collect(self::BINS)->filter(fn ($b) => $b[2])->keys()->first();
        $products = Product::with('units')->where('is_active', true)->where('is_stock_tracked', true)->orderBy('sku')->get();
        $zero = $products->firstWhere('is_batch_tracked', false)?->id;
        $soon = $products->where('is_batch_tracked', true)->take(3)->values()->pluck('id')->flip();

        $rows = [['sku', 'qty', 'bin', 'site', 'lot', 'best_before']];
        foreach ($products as $i => $p) {
            $pack = $p->units->firstWhere('default_sale', true)?->base_qty ?? 1;
            $bestBefore = $p->is_batch_tracked ? now()->addDays(isset($soon[$p->id]) ? $soon[$p->id] + 1 : 60)->toDateString() : '';
            $rows[] = [
                $p->sku,
                $p->id === $zero ? 0 : $packsEach * $pack,
                $p->is_chilled ? $cool : $ambient[$i % $ambient->count()],
                config('bwms.default_site.code'),
                $p->is_batch_tracked ? 'DEV-'.now()->format('ymd') : '',
                $bestBefore,
            ];
        }

        return $this->writeCsv('opening-stocktake.csv', $rows);
    }

    private function writeCsv(string $name, array $rows): string
    {
        $path = "dev-reset/{$name}";
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        rewind($out);
        Storage::disk('local')->put($path, stream_get_contents($out));

        return Storage::disk('local')->path($path);
    }

    private function finish(array $accounts): int
    {
        $this->newLine();
        $this->table(['What', 'Count'], [
            ['Products', Product::count()],
            ['Customers', DB::table('customers')->count()],
            ['Suppliers', DB::table('suppliers')->count()],
            ['Stock lines (bin × batch)', StockBalance::where('on_hand', '!=', 0)->count()],
            ['Ordermentum listings', ChannelListing::where('channel', 'ordermentum')->count()],
            ['Portal listings', ChannelListing::where('channel', 'portal')->count()],
            ['Users', User::count()],
        ]);
        foreach ($accounts as [$email, $role, $password]) {
            $this->line("New local account: <info>{$email}</info> ({$role}), password <info>{$password}</info>");
        }
        if (! User::role(Permissions::ROLE_ADMIN)->exists()) {
            $this->warn('No admin yet: run php artisan bwms:invite-admin you@example.com "Your Name" and open the link.');
        }
        $this->info('Dev data ready. Next: docs/TESTING.md');

        return self::SUCCESS;
    }
}
