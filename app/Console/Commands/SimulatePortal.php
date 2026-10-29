<?php

namespace App\Console\Commands;

use App\Integrations\Portal\PortalSignature;
use App\Models\ChannelListing;
use App\Models\Location;
use App\Services\Orders\AvailabilityService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Local testing (docs/TESTING.md): calls the local portal API (/api/portal/v1)
 * the way the portal does, signed with the first key in PORTAL_API_KEYS, and
 * prints each response.
 */
class SimulatePortal extends Command
{
    protected $signature = 'portal:simulate
        {action : reserve, update, cancel, status or stock}
        {--order= : order_ref (reserve: default a new SIM-… ref; required for update, cancel and status)}
        {--scenario=enough : enough, short or unknown (builds the lines for you)}
        {--lines= : PORTAL_PRODUCT_ID:qty,… in portal packs (overrides --scenario)}
        {--order-version=2 : Version for update and cancel}
        {--stale : Send version 1, older than what BWMS already has}
        {--key= : Idempotency-Key (default: a new one)}
        {--retry : Send the same request twice with the same key}
        {--bad-signature : Sign with the wrong secret; expect 401}
        {--user=9001 : portal_user_id of the café}
        {--url= : Base URL of the local app (default APP_URL)}';

    protected $description = 'Send signed portal API calls to the local app (local testing only)';

    public function handle(AvailabilityService $availability): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('portal:simulate only runs with APP_ENV=local.');

            return self::FAILURE;
        }
        $keys = config('bwms.portal.inbound_keys');
        if ($keys === [] || ! config('bwms.portal.api_enabled')) {
            $this->error('Set PORTAL_API_ENABLED=true and PORTAL_API_KEYS=local:<a long random secret> in .env, then php artisan config:clear.');

            return self::FAILURE;
        }
        $keyId = (string) array_key_first($keys);
        $secret = $this->option('bad-signature') ? 'not-the-secret' : $keys[$keyId];
        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/').'/api/portal/v1';

        $action = (string) $this->argument('action');
        $ref = (string) ($this->option('order') ?: ($action === 'reserve' ? 'SIM-'.now()->format('ymd-His') : ''));
        if ($ref === '' && in_array($action, ['update', 'cancel', 'status'], true)) {
            $this->error('--order=<order_ref> is required for '.$action.'.');

            return self::FAILURE;
        }

        $lines = in_array($action, ['reserve', 'update', 'stock'], true) ? $this->lines($availability) : [];
        if ($lines === null) {
            return self::FAILURE;
        }

        [$method, $path, $body] = match ($action) {
            'reserve' => ['POST', '/reservations', $this->orderBody($ref, $lines)],
            'update' => ['PUT', '/orders/'.rawurlencode($ref), $this->orderBody($ref, $lines) + ['version' => $this->version(), 'status' => 'confirmed']],
            'cancel' => ['PUT', '/orders/'.rawurlencode($ref), $this->orderBody($ref, []) + ['version' => $this->version(), 'status' => 'cancelled']],
            'status' => ['GET', '/orders/'.rawurlencode($ref), null],
            'stock' => ['GET', '/stock?product_ids='.implode(',', array_column($lines, 'portal_product_id')), null],
            default => [null, null, null],
        };
        if ($method === null) {
            $this->error('Action must be reserve, update, cancel, status or stock.');

            return self::FAILURE;
        }

        $raw = $body === null ? '' : json_encode($body);
        $key = (string) ($this->option('key') ?: Str::uuid());
        $sends = $this->option('retry') ? 2 : 1;
        for ($i = 1; $i <= $sends; $i++) {
            $headers = PortalSignature::headers($keyId, $secret, $raw) + ['Accept' => 'application/json'];
            if ($method !== 'GET') {
                $headers['Idempotency-Key'] = $key;
            }
            $this->line(($sends > 1 ? "[{$i}/{$sends}] " : '')."{$method} {$base}{$path}".($method !== 'GET' ? "  Idempotency-Key: {$key}" : ''));
            if ($raw !== '') {
                $this->line('  body: '.$raw);
            }
            $request = Http::withHeaders($headers)->timeout(15);
            $this->show($method === 'GET' ? $request->get($base.$path) : $request->withBody($raw, 'application/json')->send($method, $base.$path));
        }
        if ($action === 'reserve') {
            $this->line("Order ref: <info>{$ref}</info> (use --order={$ref} for update, cancel or status)");
        }

        return self::SUCCESS;
    }

    /** @return list<array{line_ref: string, portal_product_id: int, qty: int}>|null */
    private function lines(AvailabilityService $availability): ?array
    {
        if ($spec = $this->option('lines')) {
            return collect(explode(',', (string) $spec))->map('trim')->filter()->values()->map(function ($part, $i) {
                [$id, $qty] = array_pad(explode(':', $part, 2), 2, '1');

                return ['line_ref' => (string) ($i + 1), 'portal_product_id' => (int) $id, 'qty' => (int) $qty];
            })->all();
        }

        $scenario = (string) $this->option('scenario');
        if ($scenario === 'unknown') {
            return [['line_ref' => '1', 'portal_product_id' => 999999, 'qty' => 1]];
        }
        $site = Location::where('type', Location::TYPE_SITE)->where('code', config('bwms.portal.site_code'))->first();
        $listing = ChannelListing::where('channel', 'portal')->with(['product', 'unit'])->get()
            ->map(fn ($l) => [$l, intdiv(max(0, $availability->available($l->product, $site)), max(1, $l->unit->base_qty))])
            ->first(fn ($pair) => $pair[1] >= 2);
        if (! $listing) {
            $this->error('No portal listing with at least 2 packs available. Run bwms:dev-reset, or pass --lines.');

            return null;
        }
        [$l, $packs] = $listing;
        $qty = match ($scenario) {
            'enough' => 1,
            'short' => $packs + 5,
            default => null,
        };
        if ($qty === null) {
            $this->error('--scenario must be enough, short or unknown.');

            return null;
        }
        $this->line("Using portal product {$l->external_product_id} = {$l->product->sku} ({$l->unit->name}), {$packs} pack(s) available.");

        return [['line_ref' => '1', 'portal_product_id' => (int) $l->external_product_id, 'qty' => $qty]];
    }

    /** @return array<string, mixed> */
    private function orderBody(string $ref, array $lines): array
    {
        return [
            'order_ref' => $ref,
            'customer' => ['portal_user_id' => (int) $this->option('user'), 'business_name' => 'Simulated portal café'],
            'delivery' => ['street' => '1 Test Street', 'suburb' => 'Clontarf', 'postcode' => '4019'],
            'lines' => $lines,
        ];
    }

    private function version(): int
    {
        return $this->option('stale') ? 1 : (int) $this->option('order-version');
    }

    private function show(Response $response): void
    {
        $replay = $response->header('Idempotent-Replay');
        $this->line("  → HTTP <info>{$response->status()}</info>".($replay ? "  Idempotent-Replay: {$replay}" : ''));
        $json = $response->json();
        $this->line('  '.str_replace("\n", "\n  ", $json === null ? $response->body() : json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
    }
}
