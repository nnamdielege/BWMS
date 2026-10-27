<?php

namespace App\Console\Commands;

use App\Integrations\Ordermentum\OrdermentumClient;
use App\Integrations\Ordermentum\OrdermentumException;
use App\Models\ChannelListing;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diagnostics against the live Ordermentum account (docs/om-probe.md). Every
 * mode is read-only (GET) except `write-test`, which needs --confirm and only
 * re-writes the value it just read. `tracking` stores what it reads on the local
 * listings; `secret --write-env` writes the webhook secret to the local .env.
 */
class OrdermentumProbe extends Command
{
    protected $signature = 'om:probe
        {what : order, stock, tracking, recent, locations, secret or write-test}
        {id? : Ordermentum order ID (order) or variant ID (stock, write-test)}
        {--json : Print only the raw response body}
        {--structure : order: print field names and types only, plus line item ID/quantity values (no customer details)}
        {--no-include-lines : order: do not add includeLines=true}
        {--location= : stock: locationId query (default ORDERMENTUM_LOCATION_ID)}
        {--headers= : stock: context headers to send (plain, x, both); order: test each style against the first line variant}
        {--label= : stock: note recorded with the reading}
        {--limit=5 : recent: how many orders to list}
        {--write-env : secret: write ORDERMENTUM_WEBHOOK_SECRET to .env (the value is never printed)}
        {--confirm : write-test: required; PUTs/PATCHes the value just read back to Ordermentum}';

    protected $description = 'Inspect Ordermentum orders, stock, tracking and the webhook secret (read-only, except write-test)';

    /** Keys worth highlighting when looking for line item product/variant IDs and quantities. */
    private const FIELD_PATTERN = '/product|variant|sku|quantity|qty/i';

    public const LOG_PATH = 'logs/om-probe.jsonl';

    public function handle(OrdermentumClient $client): int
    {
        $what = (string) $this->argument('what');
        if (in_array($what, ['order', 'stock', 'write-test'], true) && blank($this->argument('id'))) {
            return $this->failWith("om:probe {$what} needs an ID.");
        }

        try {
            return match ($what) {
                'order' => $this->probeOrder($client, (string) $this->argument('id')),
                'stock' => $this->probeStock($client, (string) $this->argument('id')),
                'tracking' => $this->probeTracking($client),
                'recent' => $this->probeRecent($client),
                'secret' => $this->probeSecret($client),
                'locations' => $this->probeLocations($client),
                'write-test' => $this->writeTest($client, (string) $this->argument('id')),
                default => $this->failWith('First argument must be order, stock, tracking, recent, locations, secret or write-test.'),
            };
        } catch (OrdermentumException $e) {
            return $this->failWith($e->getMessage());
        }
    }

    private function probeOrder(OrdermentumClient $client, string $id): int
    {
        $query = $this->option('no-include-lines') ? [] : ['includeLines' => 'true'];
        $response = $client->get('/v1/orders/'.rawurlencode($id), $query);

        if ($this->option('json') || ! $response->successful()) {
            return $this->printRawOrFail($response, "GET /v1/orders/{$id}");
        }

        $data = (array) $response->json();
        $this->printHeader("Order {$id}", $response);
        if (! $this->option('structure')) {
            $this->printHighlightedJson($data);
            $this->newLine();
        }

        $lineArrays = $this->findLineArrays($data);
        if ($lineArrays === []) {
            $this->warn('No line array found in the response (looked for lists of objects under a key containing "line" or "item").');
        } else {
            foreach ($lineArrays as $path => $count) {
                $this->info("Line array: {$path} ({$count} entries)");
            }
        }

        if ($this->option('structure')) {
            $rows = [];
            $this->collectStructure($data, '', $rows, array_keys($lineArrays));
            $this->table(['Path', 'Type', 'Value (line ID/quantity fields only)'], $rows);
        } else {
            $rows = [];
            $this->collectFields($data, '', $rows);
            $rows === []
                ? $this->warn('No product, variant, SKU or quantity fields found.')
                : $this->table(['Path', 'Value'], $rows);
        }

        if ($this->option('headers') && $lineArrays !== []) {
            $this->checkHeaders($client, $data, array_key_first($lineArrays));
        }

        return self::SUCCESS;
    }

    /**
     * For each candidate ID field on the first line, GET it from the stock API with each
     * context-header style separately: shows which field is the stock item ID and which header works.
     */
    private function checkHeaders(OrdermentumClient $client, array $data, string $linePath): void
    {
        $line = (array) (data_get($data, $linePath)[0] ?? []);
        $candidates = [];
        array_walk_recursive($line, function ($value, $key) use (&$candidates) {
            if (is_string($value) && preg_match('/^[0-9a-f-]{32,36}$/i', $value)) {
                $candidates[] = $value;
            }
        });
        $flat = $this->flatten($line);
        $styles = $this->option('headers') === 'both' ? ['plain', 'x'] : [(string) $this->option('headers')];
        $location = config('services.ordermentum.location_id');

        $rows = [];
        foreach (array_unique($candidates) as $value) {
            $field = implode(' = ', array_map(fn ($k) => "{$linePath}.0.{$k}", array_keys($flat, $value, true)));
            foreach ($styles as $style) {
                $r = $client->getStock('/v1/items/'.rawurlencode($value), $location ? ['locationId' => $location] : [], $style);
                $rows[] = [$field, $style, $r->status(), $r->successful() ? $this->scalar($r->json('tracked')) : '-'];
            }
        }
        $this->newLine();
        $this->line('Stock API lookups of the first line\'s ID fields, per context-header style:');
        $rows === [] ? $this->warn('No UUID-like fields on the first line.') : $this->table(['Line field', 'Headers', 'HTTP', 'tracked'], $rows);
    }

    private function probeStock(OrdermentumClient $client, string $id): int
    {
        $location = $this->option('location') ?? config('services.ordermentum.location_id');
        $headers = $this->option('headers') ?: null;
        $query = filled($location) ? ['locationId' => $location] : [];

        $response = $client->getStock('/v1/items/'.rawurlencode($id), $query, $headers);
        $at = Carbon::now();
        $data = $response->json();

        $this->record($at, $id, $location, $headers ?? (string) config('services.ordermentum.context_headers'), $response, is_array($data) ? $data : null);

        if ($this->option('json') || ! $response->successful()) {
            return $this->printRawOrFail($response, "GET stock /v1/items/{$id}");
        }

        $this->printHeader("Stock item {$id}", $response, $at);
        $this->line('Context headers sent: '.implode(', ', array_keys($client->contextHeaders($headers))));
        $this->line('locationId: '.($location ?: '(none)'));
        if ($this->option('label')) {
            $this->line('Label: '.$this->option('label'));
        }
        $this->newLine();
        $this->table(['available', 'tracked', 'allowOversell'], [[
            $this->scalar(data_get($data, 'available')),
            $this->scalar(data_get($data, 'tracked')),
            $this->scalar(data_get($data, 'allowOversell')),
        ]]);

        $perLocation = data_get($data, 'stock');
        if (is_array($perLocation) && $perLocation !== []) {
            $this->table(['locationId', 'available'], array_map(
                fn ($s) => [$this->scalar(data_get($s, 'locationId')), $this->scalar(data_get($s, 'available'))],
                $perLocation,
            ));
        }

        $this->line('Raw response:');
        $this->printHighlightedJson($data);
        $this->line('Recorded in storage/'.self::LOG_PATH);

        return self::SUCCESS;
    }

    /**
     * Mapped listings whose Ordermentum item has stock tracking off. Ordermentum only
     * decrements tracked items at order placement, so these need attention before cutover.
     * The result is stored on each listing and shown on the sync screen.
     */
    private function probeTracking(OrdermentumClient $client): int
    {
        $listings = ChannelListing::where('channel', 'ordermentum')->with('product:id,sku,name')->orderBy('id')->get();
        if ($listings->isEmpty()) {
            return $this->failWith('No Ordermentum listings. Run the legacy migration (or map variants) first.');
        }
        $location = config('services.ordermentum.location_id');
        $this->info("Checking {$listings->count()} mapped variants (read-only; rate-limited)...");

        $rows = [];
        $counts = ['tracked' => 0, 'untracked' => 0, 'missing' => 0, 'error' => 0];
        foreach ($listings as $listing) {
            $r = $this->waitingOutLimits(fn () => $client->getStock('/v1/items/'.rawurlencode($listing->external_variant_id), $location ? ['locationId' => $location] : []));
            $tracked = $r->successful() ? (bool) $r->json('tracked') : null;
            $state = match (true) {
                $r->status() === 404 => 'missing',
                ! $r->successful() => 'error',
                $tracked => 'tracked',
                default => 'untracked',
            };
            $counts[$state]++;
            $listing->forceFill(['remote_tracked' => $tracked, 'remote_status' => $state, 'last_checked_at' => now()])->save();
            if ($state !== 'tracked') {
                $rows[] = [$listing->product?->sku, $listing->external_variant_id, $state, $r->status()];
            }
        }

        $this->table(['Tracked', 'Not tracked', 'Not found (404)', 'Error'], [array_values($counts)]);
        $rows === []
            ? $this->info('Every mapped variant has stock tracking on.')
            : $this->table(['SKU', 'Variant', 'State', 'HTTP'], $rows);

        return self::SUCCESS;
    }

    /** A CLI run waits out rate-limit windows longer than the client's own short wait (a queued job would release instead). */
    private function waitingOutLimits(callable $call): Response
    {
        for ($i = 0; ; $i++) {
            try {
                return $call();
            } catch (OrdermentumException $e) {
                if ($e->retryAfter === null || $i >= 5) {
                    throw $e;
                }
                $this->line("  rate limit: waiting {$e->retryAfter}s");
                Sleep::for($e->retryAfter)->seconds();
            }
        }
    }

    /**
     * The webhook signing secret: `sharedSecret` on GET /v1/suppliers/{supplierId}
     * (confirmed by Ordermentum, 2026-10-27). Prints found/length only; never the value.
     */
    private function probeSecret(OrdermentumClient $client): int
    {
        $supplierId = (string) config('services.ordermentum.supplier_id');
        if ($supplierId === '') {
            return $this->failWith('ORDERMENTUM_SUPPLIER_ID is not set.');
        }
        $response = $client->get('/v1/suppliers/'.rawurlencode($supplierId));
        if (! $response->successful()) {
            return $this->failWith("GET /v1/suppliers/{id} returned HTTP {$response->status()}."); // body not echoed: it may hold the secret
        }

        $secret = null;
        $data = (array) $response->json();
        array_walk_recursive($data, function ($value, $key) use (&$secret) {
            if ($key === 'sharedSecret' && is_string($value) && $value !== '') {
                $secret ??= $value;
            }
        });
        if ($secret === null) {
            return $this->failWith('sharedSecret: not found. Top-level keys: '.implode(', ', array_keys($data)));
        }
        $this->info('sharedSecret: found (length '.strlen($secret).')');

        if ($this->option('write-env')) {
            $this->writeEnv('ORDERMENTUM_WEBHOOK_SECRET', $secret);
            $this->info('Wrote ORDERMENTUM_WEBHOOK_SECRET to .env. Run php artisan config:clear (or config:cache) to load it.');
        }

        return self::SUCCESS;
    }

    private function writeEnv(string $name, string $value): void
    {
        $path = $this->laravel->environmentFilePath();
        $line = $name.'="'.addcslashes($value, '"\\$').'"';
        $env = File::exists($path) ? File::get($path) : '';
        $env = preg_match("/^{$name}=.*$/m", $env)
            ? preg_replace("/^{$name}=.*$/m", str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $env)
            : rtrim($env, "\n")."\n{$line}\n";
        File::put($path, $env);
    }

    /**
     * Proves the write paths without changing anything: read the item, PUT the same
     * value (supplier headers plain, then x-), PATCH UPDATE the same value, reading
     * after each step. A changed figure is reported (a real order may have landed)
     * and the original is put back.
     */
    private function writeTest(OrdermentumClient $client, string $variantId): int
    {
        if (! $this->option('confirm')) {
            return $this->failWith('write-test writes to Ordermentum. Re-run with --confirm.');
        }
        $location = config('services.ordermentum.location_id');
        $path = '/v1/items/'.rawurlencode($variantId);
        $read = function () use ($client, $path, $location) {
            $r = $this->waitingOutLimits(fn () => $client->getStock($path, $location ? ['locationId' => $location] : []));

            return [$r->status(), $r->successful() ? $r->json() : null];
        };

        [$status, $before] = $read();
        if ($before === null) {
            return $this->failWith("Initial read returned HTTP {$status}; nothing was written.");
        }
        if (! is_numeric($before['available'] ?? null)) {
            // No stock row at this location (e.g. a wrong ORDERMENTUM_LOCATION_ID): writing would create one.
            return $this->failWith('No available figure at location '.($location ?: '(default)').'; nothing was written. Check ORDERMENTUM_LOCATION_ID with om:probe locations.');
        }
        $value = (int) $before['available'];
        $put = array_filter([
            'available' => $value, 'tracked' => (bool) ($before['tracked'] ?? true), 'allowOversell' => (bool) ($before['allowOversell'] ?? false),
            'locationId' => $location, 'supplierId' => config('services.ordermentum.supplier_id'),
        ], fn ($v) => $v !== null && $v !== '');
        $productId = ChannelListing::where('channel', 'ordermentum')->where('external_variant_id', $variantId)->value('external_product_id');
        $listing = ChannelListing::where('channel', 'ordermentum')->where('external_variant_id', $variantId)->with('product:id,sku,name')->first();
        if (blank($productId)) {
            return $this->failWith('This variant has no mapped Ordermentum productId (required by PATCH); nothing was written.');
        }
        $patch = ['actions' => [array_filter(['id' => $variantId, 'productId' => $productId, 'name' => $listing?->product?->name ?? $variantId,
            'action' => 'UPDATE', 'quantity' => $value, 'locationId' => $location], fn ($v) => $v !== null && $v !== '')]];

        $rows = [['read', $status, $value, 'baseline']];
        $changed = false;
        $failed = [];
        foreach ([
            'PUT (context-*)' => fn () => $client->putStock($path, $put, 'plain'),
            'PUT (x-context-*)' => fn () => $client->putStock($path, $put, 'x'),
            'PATCH UPDATE' => fn () => $client->patchStock('/v1/items', $patch),
        ] as $label => $write) {
            $w = $this->waitingOutLimits($write);
            [$readStatus, $after] = $read();
            $now = $after === null ? null : (int) ($after['available'] ?? 0);
            $same = $now === $value;
            $changed = $changed || ! $same;
            $rows[] = [$label, $w->status(), $now ?? "read HTTP {$readStatus}", $same ? 'unchanged' : 'CHANGED'];
            if (! $w->successful()) {
                // Error bodies describe the request shape; they hold no stock or customer data.
                $failed[] = "{$label}: HTTP {$w->status()} ".mb_strimwidth($w->body(), 0, 600, '...');
            }
            if (! $same) {
                break; // stop writing; restore below
            }
        }
        $this->table(['Step', 'HTTP', 'available after', 'Value'], $rows);

        if ($changed) {
            $restore = $this->waitingOutLimits(fn () => $client->putStock($path, $put));
            $this->warn("The figure changed during the test (a real order may have landed). Put the original {$value} back: HTTP {$restore->status()}.");

            return self::FAILURE;
        }
        foreach ($failed as $line) {
            $this->error(OutputFormatter::escape($line));
        }
        if ($failed !== []) {
            $this->warn("available stayed at {$value}, but ".count($failed).' write(s) were rejected.');

            return self::FAILURE;
        }
        $this->info("Every write was accepted and available stayed at {$value}.");

        return self::SUCCESS;
    }

    /** Ordermentum stock locations, marking the configured one: ORDERMENTUM_LOCATION_ID must be where stock lives. */
    private function probeLocations(OrdermentumClient $client): int
    {
        $response = $client->getStock('/v1/locations');
        if (! $response->successful()) {
            return $this->printRawOrFail($response, 'GET stock /v1/locations');
        }
        $configured = (string) config('services.ordermentum.location_id');
        $data = $response->json();
        $this->table(['id', 'name', 'default', 'configured'], array_map(fn ($l) => [
            $l['id'] ?? '', $l['name'] ?? '', ! empty($l['default']) ? 'yes' : '', ($l['id'] ?? null) === $configured ? '<- ORDERMENTUM_LOCATION_ID' : '',
        ], (array) ($data['data'] ?? $data)));

        return self::SUCCESS;
    }

    /** Recent order IDs with status and dates only, to pick one for `om:probe order`. */
    private function probeRecent(OrdermentumClient $client): int
    {
        $response = $client->get('/v2/orders', [
            'supplierId' => config('services.ordermentum.supplier_id'), 'pageNo' => 1, 'pageSize' => (int) $this->option('limit'),
        ]);
        if (! $response->successful()) {
            return $this->printRawOrFail($response, 'GET /v2/orders');
        }
        $this->table(['id', 'status', 'createdAt', 'updatedAt', 'cancelled'], array_map(fn ($o) => [
            $o['id'] ?? '', $o['status'] ?? '', $o['createdAt'] ?? '', $o['updatedAt'] ?? '', filled($o['cancelledAt'] ?? null) ? 'yes' : '',
        ], (array) $response->json('data')));

        return self::SUCCESS;
    }

    private function printHeader(string $title, Response $response, ?Carbon $at = null): void
    {
        $at ??= Carbon::now();
        $this->info($title);
        $this->line(sprintf(
            'Fetched %s (%s) | HTTP %d',
            $at->copy()->setTimezone(config('bwms.business_timezone'))->format('Y-m-d H:i:s T'),
            $at->copy()->utc()->toIso8601String(),
            $response->status(),
        ));
        $this->newLine();
    }

    /** Appends one stock reading (stock figures only) to a local timeline. */
    private function record(Carbon $at, string $id, ?string $location, string $headers, Response $response, ?array $data): void
    {
        File::ensureDirectoryExists(dirname(storage_path(self::LOG_PATH)));
        File::append(storage_path(self::LOG_PATH), json_encode([
            'at' => $at->copy()->utc()->toIso8601String(),
            'label' => $this->option('label'),
            'item_id' => $id,
            'location_id' => $location,
            'headers' => $headers,
            'status' => $response->status(),
            'available' => data_get($data, 'available'),
            'tracked' => data_get($data, 'tracked'),
            'stock' => data_get($data, 'stock'),
        ], JSON_UNESCAPED_SLASHES)."\n");
    }

    private function printRawOrFail(Response $response, string $what): int
    {
        if ($response->successful()) {
            $this->output->writeln($response->body(), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        return $this->failWith("{$what} returned HTTP {$response->status()}: ".mb_strimwidth($response->body(), 0, 2000, '...'));
    }

    /** Pretty JSON with product/variant/SKU/quantity keys highlighted. */
    private function printHighlightedJson(mixed $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach (explode("\n", (string) $json) as $line) {
            $escaped = OutputFormatter::escape($line);
            $this->line(preg_replace_callback('/^(\s*)"([^"]+)":/', fn ($m) => preg_match(self::FIELD_PATTERN, $m[2])
                ? "{$m[1]}<fg=yellow;options=bold>\"{$m[2]}\"</>:"
                : $m[0], $escaped));
        }
    }

    /**
     * Leaves whose path has a key matching FIELD_PATTERN, e.g. lineItems.0.variantId
     * or lineItems.0.product.id.
     *
     * @param  list<array{0: string, 1: string}>  $rows
     */
    private function collectFields(mixed $node, string $path, array &$rows, bool $matched = false): void
    {
        if (! is_array($node)) {
            if ($matched) {
                $rows[] = [$path, $this->scalar($node)];
            }

            return;
        }
        foreach ($node as $key => $value) {
            $isMatch = $matched || (is_string($key) && preg_match(self::FIELD_PATTERN, $key) === 1);
            $this->collectFields($value, $path === '' ? (string) $key : "{$path}.{$key}", $rows, $isMatch);
        }
    }

    /**
     * Every field path with its type; a value only for ID-like and quantity fields
     * inside line arrays. Retailer names, addresses, notes etc. are never printed.
     * Only the first entry of each list is described.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $rows
     * @param  list<string>  $linePaths
     */
    private function collectStructure(mixed $node, string $path, array &$rows, array $linePaths): void
    {
        if (is_array($node) && $node !== []) {
            if (array_is_list($node)) {
                $rows[] = [$path, 'list('.count($node).')', ''];
                $this->collectStructure($node[0], "{$path}.0", $rows, $linePaths);

                return;
            }
            foreach ($node as $key => $value) {
                $this->collectStructure($value, $path === '' ? (string) $key : "{$path}.{$key}", $rows, $linePaths);
            }

            return;
        }

        $inLine = collect($linePaths)->contains(fn ($p) => str_starts_with($path, "{$p}.0."));
        $leaf = (string) last(explode('.', $path));
        $showValue = $inLine && preg_match('/(^id$|Id$|_id$|quantity|qty)/i', $leaf);
        $rows[] = [$path, get_debug_type($node), $showValue ? $this->scalar($node) : ''];
    }

    /** @return array<string, mixed> dot path => leaf */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string) $k : "{$prefix}.{$k}";
            is_array($v) ? $out += $this->flatten($v, $key) : $out[$key] = $v;
        }

        return $out;
    }

    /** @return array<string, int> path => entry count */
    private function findLineArrays(mixed $node, string $path = ''): array
    {
        if (! is_array($node)) {
            return [];
        }
        $found = [];
        foreach ($node as $key => $value) {
            $childPath = $path === '' ? (string) $key : "{$path}.{$key}";
            if (is_string($key) && preg_match('/line|item/i', $key) && is_array($value) && array_is_list($value)
                && $value !== [] && is_array($value[0])) {
                $found[$childPath] = count($value);
            }
            $found += $this->findLineArrays($value, $childPath);
        }

        return $found;
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_SLASHES),
        };
    }

    private function failWith(string $message): int
    {
        $this->error(OutputFormatter::escape($message));

        return self::FAILURE;
    }
}
