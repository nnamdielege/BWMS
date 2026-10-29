<?php

namespace App\Console\Commands;

use App\Integrations\Ordermentum\LocalOrderFixtures;
use App\Integrations\Ordermentum\OrdermentumClient;
use App\Integrations\Ordermentum\WebhookSignature;
use App\Models\ChannelListing;
use App\Models\ChannelUnmappedVariant;
use App\Models\Customer;
use App\Models\IntegrationInbox;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Local testing (docs/TESTING.md): sends a signed Ordermentum webhook to the
 * local app, the way Ordermentum would, then runs the queue and shows the result.
 *
 * The order itself is a local fixture (LocalOrderFixtures): fetched once from
 * Ordermentum with a read-only GET, or built from --lines. "updated" and
 * "cancelled" change the fixture, so nothing in Ordermentum changes.
 */
class SimulateOrdermentumWebhook extends Command
{
    protected $signature = 'om:simulate-webhook
        {orderId : Ordermentum order ID: a real one (fetched read-only), or any new ID with --lines}
        {--event=created : created, updated or cancelled}
        {--lines= : Build a fake order instead: SKU:qty,SKU:qty (for "updated": the new quantities)}
        {--customer= : Customer code for a fake order (its Ordermentum retailer ID and delivery address)}
        {--unmapped : Add a line whose variant has no listing (the order is held)}
        {--bad-signature : Sign with the wrong secret; expect 401}
        {--url= : Base URL of the local app (default APP_URL)}
        {--no-process : Do not run the queue afterwards}';

    protected $description = 'Send a signed Ordermentum webhook to the local app (local testing only)';

    public function handle(LocalOrderFixtures $fixtures, OrdermentumClient $client): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('om:simulate-webhook only runs with APP_ENV=local.');

            return self::FAILURE;
        }
        $event = (string) $this->option('event');
        if (! in_array($event, ['created', 'updated', 'cancelled'], true)) {
            $this->error('--event must be created, updated or cancelled.');

            return self::FAILURE;
        }
        if (! $fixtures->enabled()) {
            $this->error('Set ORDERMENTUM_LOCAL_FIXTURES=true in .env (then php artisan config:clear), so the app uses the simulated order.');

            return self::FAILURE;
        }
        $secret = (string) config('bwms.ordermentum.webhook_signature.secret');
        $token = (string) config('bwms.ordermentum.webhook_token');
        if ($secret === '' || $token === '') {
            $this->error('Set ORDERMENTUM_WEBHOOK_SECRET and ORDERMENTUM_WEBHOOK_TOKEN in .env (see docs/TESTING.md).');

            return self::FAILURE;
        }

        $orderId = (string) $this->argument('orderId');
        $order = $this->order($fixtures, $client, $orderId, $event);
        if ($order === null) {
            return self::FAILURE;
        }
        $fixtures->put($orderId, $order);

        // Ordermentum sends order_created / order_updated; a cancellation arrives as an update.
        $body = json_encode(['event' => $event === 'created' ? 'order_created' : 'order_updated', 'data' => ['id' => $orderId, 'updatedAt' => $order['updatedAt']]]);
        $signature = WebhookSignature::sign($body, $this->option('bad-signature') ? 'not-the-secret' : $secret);
        $url = rtrim((string) ($this->option('url') ?: config('app.url')), '/').'/webhooks/ordermentum/'.$token;

        $this->line("POST {$url}  ({$event}, ".count($order['lineItems']).' line(s))');
        $response = Http::withHeaders([(string) config('bwms.ordermentum.webhook_signature.header') => $signature])
            ->withBody($body, 'application/json')->timeout(15)->post($url);
        $this->line("HTTP {$response->status()} ".$response->body());

        if ($this->option('bad-signature')) {
            $response->status() === 401 ? $this->info('Rejected as expected (bad signature).') : $this->error('Expected 401.');

            return $response->status() === 401 ? self::SUCCESS : self::FAILURE;
        }
        if (! $response->successful()) {
            return self::FAILURE;
        }

        if (! $this->option('no-process') && config('queue.default') !== 'sync') {
            $this->callSilently('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);
        }
        $this->report($orderId);

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null the order as the ingestion will see it */
    private function order(LocalOrderFixtures $fixtures, OrdermentumClient $client, string $orderId, string $event): ?array
    {
        $order = $fixtures->get($orderId);

        if ($this->option('lines')) {
            $lines = $this->lines((string) $this->option('lines'));
            if ($lines === null) {
                return null;
            }
            $order = ($order ?? $this->fakeOrder($orderId)) + ['lineItems' => []];
            $order['lineItems'] = $lines;
        } elseif ($order === null) {
            $this->line("Fetching order {$orderId} from Ordermentum (read-only GET)…");
            $response = $client->get('/v1/orders/'.rawurlencode($orderId), ['includeLines' => 'true']);
            if (! $response->successful()) {
                $this->error("Ordermentum returned HTTP {$response->status()} for {$orderId}. Use --lines to build a fake order instead.");

                return null;
            }
            $order = (array) $response->json();
        } elseif ($event === 'updated' && ! empty($order['lineItems'])) {
            $order['lineItems'][0]['quantity'] = (int) $order['lineItems'][0]['quantity'] + 1; // a visible edit
        }

        if ($this->option('unmapped')) {
            $order['lineItems'][] = ['id' => 'sim-unmapped', 'variantId' => 'sim-unmapped-'.Str::lower(Str::random(6)), 'quantity' => 1, 'price' => '0', 'sku' => 'SIM-UNMAPPED', 'name' => 'Simulated unmapped item'];
        }
        if ($event === 'cancelled') {
            $order['status'] = 'cancelled';
            $order['cancelledAt'] = now()->toIso8601String();
        }
        // A new updatedAt each time: the inbox dedupes on event + order + updatedAt.
        $order['updatedAt'] = now()->format('Y-m-d\TH:i:s.vP');

        return $order;
    }

    /** @return array<string, mixed> */
    private function fakeOrder(string $orderId): array
    {
        $customer = $this->option('customer')
            ? Customer::where('code', $this->option('customer'))->first()
            : (Customer::whereNotNull('ordermentum_retailer_id')->whereNotNull('delivery_postcode')->first() ?? Customer::whereNotNull('delivery_postcode')->first());
        if ($this->option('customer') && ! $customer) {
            $this->warn("No customer {$this->option('customer')}; the order has no customer or address.");
        }

        return [
            'id' => $orderId,
            'status' => 'confirmed',
            'retailerId' => $customer?->ordermentum_retailer_id,
            'retailer' => ['name' => $customer?->name],
            'address' => ['street1' => $customer?->delivery_street, 'suburb' => $customer?->delivery_suburb, 'postcode' => $customer?->delivery_postcode],
            'deliveryDate' => null,
        ];
    }

    /** @return list<array<string, mixed>>|null */
    private function lines(string $spec): ?array
    {
        $lines = [];
        foreach (array_filter(array_map('trim', explode(',', $spec))) as $i => $part) {
            [$sku, $qty] = array_pad(explode(':', $part, 2), 2, '1');
            $product = Product::where('sku', $sku)->first();
            $listing = $product ? ChannelListing::where('channel', 'ordermentum')->where('product_id', $product->id)->first() : null;
            if (! $listing) {
                $this->error("{$sku}: no product with an Ordermentum listing. Use --unmapped to test an unmapped line.");

                return null;
            }
            $lines[] = ['id' => 'sim-'.($i + 1), 'variantId' => $listing->external_variant_id, 'quantity' => (int) $qty, 'price' => (string) ($product->sell_price ?? '0'), 'sku' => $sku, 'name' => $product->name];
        }

        return $lines;
    }

    private function report(string $orderId): void
    {
        $inbox = IntegrationInbox::where('channel', 'ordermentum')->where('external_id', $orderId)->latest('id')->first();
        $this->newLine();
        $this->line('Inbox: <info>'.($inbox?->status ?? 'nothing stored').'</info>'.($inbox?->signature_status ? " (signature {$inbox->signature_status})" : '').($inbox?->error ? " — {$inbox->error}" : ''));
        if ($inbox?->status === IntegrationInbox::STATUS_RECEIVED) {
            $this->warn('Still queued: run php artisan queue:work (or drop --no-process).');
        }

        $order = Order::where('channel', 'ordermentum')->where('external_ref', $orderId)->with(['lines.product', 'lines.reservation', 'deliveryRun'])->first();
        if (! $order) {
            $this->line('Order: none in BWMS yet.');
        } else {
            $this->line("Order: <info>{$order->number}</info> {$order->status->label()}".($order->oversold ? ' · OVERSOLD' : '').' · run '.($order->deliveryRun?->label() ?? 'none'));
            $this->table(['SKU', 'Ordered (base)', 'Reserved', 'Cancelled'], $order->lines->map(fn ($l) => [
                $l->product->sku, $l->qty_base, $l->reservation?->qty_base ?? 0, $l->qty_cancelled_base,
            ]));
        }
        $held = ChannelUnmappedVariant::where('channel', 'ordermentum')->whereNull('resolved_at')->count();
        if ($held) {
            $this->line("Unmapped Ordermentum items waiting on the Sync screen: {$held}");
        }
    }
}
