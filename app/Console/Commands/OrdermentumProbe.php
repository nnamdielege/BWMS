<?php

namespace App\Console\Commands;

use App\Integrations\Ordermentum\OrdermentumClient;
use App\Integrations\Ordermentum\OrdermentumException;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only diagnostics against the live Ordermentum account, used to close
 * spec B11 items 1, 2 and 4 (line item fields, stock decrement, stock API
 * headers). Procedure: docs/om-probe.md. Only GET requests reach Ordermentum
 * (plus the login POST while on username/password auth).
 */
class OrdermentumProbe extends Command
{
    protected $signature = 'om:probe
        {what : order or stock}
        {id : Ordermentum order ID, or stock item ID (variant ID, unconfirmed)}
        {--json : Print only the raw response body}
        {--no-include-lines : order: do not add includeLines=true}
        {--location= : stock: locationId query (default ORDERMENTUM_LOCATION_ID)}
        {--headers=both : stock: context headers to send: both, plain (context-*) or x (x-context-*)}
        {--label= : stock: note recorded with the reading, e.g. "after placed"}';

    protected $description = 'Read-only: fetch one Ordermentum order or one stock item and print the raw response';

    /** Keys worth highlighting when looking for line item product/variant IDs and quantities. */
    private const FIELD_PATTERN = '/product|variant|sku|quantity|qty/i';

    public const LOG_PATH = 'logs/om-probe.jsonl';

    public function handle(OrdermentumClient $client): int
    {
        try {
            return match ($this->argument('what')) {
                'order' => $this->probeOrder($client, (string) $this->argument('id')),
                'stock' => $this->probeStock($client, (string) $this->argument('id')),
                default => $this->failWith('First argument must be "order" or "stock".'),
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

        $data = $response->json();
        $this->printHeader("Order {$id}", $response, $client);
        $this->printHighlightedJson($data);

        $this->newLine();
        $lineArrays = $this->findLineArrays($data);
        if ($lineArrays === []) {
            $this->warn('No line array found in the response (looked for lists of objects under a key containing "line" or "item").');
        } else {
            foreach ($lineArrays as $path => $count) {
                $this->info("Line array: {$path} ({$count} entries)");
            }
        }

        $rows = [];
        $this->collectFields($data, '', $rows);
        if ($rows === []) {
            $this->warn('No product, variant, SKU or quantity fields found.');
        } else {
            $this->line('Product / variant / SKU / quantity fields:');
            $this->table(['Path', 'Value'], $rows);
        }

        return self::SUCCESS;
    }

    private function probeStock(OrdermentumClient $client, string $id): int
    {
        $location = $this->option('location') ?? config('services.ordermentum.location_id');
        $headers = (string) $this->option('headers');
        $query = filled($location) ? ['locationId' => $location] : [];

        $response = $client->getStock('/v1/items/'.rawurlencode($id), $query, $headers);
        $at = Carbon::now();
        $data = $response->json();

        $this->record($at, $id, $location, $headers, $response, is_array($data) ? $data : null);

        if ($this->option('json') || ! $response->successful()) {
            return $this->printRawOrFail($response, "GET stock /v1/items/{$id}");
        }

        $this->printHeader("Stock item {$id}", $response, $client, $at);
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

    private function printHeader(string $title, Response $response, OrdermentumClient $client, ?Carbon $at = null): void
    {
        $at ??= Carbon::now();
        $this->info($title);
        $this->line(sprintf(
            'Fetched %s (%s) | HTTP %d | auth: %s',
            $at->copy()->setTimezone(config('bwms.business_timezone'))->format('Y-m-d H:i:s T'),
            $at->copy()->utc()->toIso8601String(),
            $response->status(),
            $client->authMode(),
        ));
        $this->newLine();
    }

    /** Appends one stock reading so a decrement test leaves a timeline (docs/om-probe.md). */
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
