<?php

namespace App\Integrations\Ordermentum;

use GuzzleHttp\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use LogicException;

/**
 * The one HTTP client for Ordermentum (NFR-1). A singleton holding one curl
 * handler, so connections and DNS lookups are reused across calls.
 *
 * Auth: API token in `x-api-key` (confirmed by Ordermentum, 2026-10-27). The
 * token is never logged and never part of an exception message.
 *
 * Rate limits: a client-side budget per endpoint group (config
 * services.ordermentum.rate_limits; stock PUT 20/min and POST 12/min are
 * confirmed), plus Ordermentum's own 429 + Retry-After. A short wait is slept
 * out; a longer one throws with retryAfter so a queued job can release itself.
 *
 * Only for console commands and queued jobs: it refuses to run during a web
 * request or inside a DB transaction (NFR-2).
 */
class OrdermentumClient
{
    private const MAX_ATTEMPTS = 3;

    /** @var callable */
    private $handler;

    public function __construct(private array $config)
    {
        $this->handler = Utils::chooseHandler();
    }

    /** GET on the core API (api.ordermentum.com). */
    public function get(string $path, array $query = []): Response
    {
        return $this->send('core', 'GET', $path, fn () => $this->request()->get($this->url('base_url', $path), $query));
    }

    /** GET on the stock API (stock.ordermentum.com), with the supplier context headers. */
    public function getStock(string $path, array $query = [], ?string $contextHeaders = null): Response
    {
        return $this->send('stock', 'GET', $path, fn () => $this->request()
            ->withHeaders($this->contextHeaders($contextHeaders))
            ->get($this->url('stock_url', $path), $query));
    }

    /** Absolute stock level for one item: PUT /v1/items/{variantId}. Retries are naturally idempotent. */
    public function putStock(string $path, array $body): Response
    {
        return $this->send('stock', 'PUT', $path, fn () => $this->request()
            ->withHeaders($this->contextHeaders())
            ->put($this->url('stock_url', $path), $body));
    }

    /** Bulk stock actions: PATCH /v1/items {actions: [...]}. */
    public function patchStock(string $path, array $body): Response
    {
        return $this->send('stock', 'PATCH', $path, fn () => $this->request()
            ->withHeaders($this->contextHeaders())
            ->patch($this->url('stock_url', $path), $body));
    }

    /**
     * Supplier context headers for the stock API. The docs name them both
     * `context-*` and `x-context-*` (spec B3.1); config picks one, `both`
     * sends all four (om:probe uses this to find the right one).
     *
     * @return array<string, string>
     */
    public function contextHeaders(?string $which = null): array
    {
        $supplierId = $this->config['supplier_id'] ?? null;
        if (blank($supplierId)) {
            throw new OrdermentumException('ORDERMENTUM_SUPPLIER_ID is not set; the stock API needs it.');
        }
        $plain = ['context-entity' => 'supplier', 'context-id' => (string) $supplierId];
        $x = ['x-context-entity' => 'supplier', 'x-context-id' => (string) $supplierId];

        return match ($which ?? $this->config['context_headers'] ?? 'plain') {
            'plain' => $plain,
            'x' => $x,
            'both' => $plain + $x,
            default => throw new LogicException("Unknown context header set [{$which}]."),
        };
    }

    /** @param  callable(): Response  $call */
    private function send(string $host, string $method, string $path, callable $call): Response
    {
        $this->assertSafeContext();

        for ($attempt = 1; ; $attempt++) {
            $this->takeBudget($host, $method, $path);

            try {
                $response = $call();
            } catch (ConnectionException $e) {
                throw new OrdermentumException('Ordermentum unreachable: '.$e->getMessage());
            }

            if ($response->status() === 429) {
                $wait = max(1, (int) $response->header('Retry-After'));
                if ($attempt < self::MAX_ATTEMPTS && $wait <= $this->config['max_retry_wait']) {
                    Sleep::for($wait)->seconds();

                    continue;
                }
                throw new OrdermentumException("Ordermentum rate limit hit (429, Retry-After {$wait}s).", 429, $wait);
            }

            return $response;
        }
    }

    /** Client-side per-endpoint budget, so we rarely meet Ordermentum's 429 at all. */
    private function takeBudget(string $host, string $method, string $path): void
    {
        [$key, $perMinute] = $this->limitFor($host, $method, $path);
        if ($perMinute <= 0) {
            return;
        }

        for ($i = 0; RateLimiter::tooManyAttempts($key, $perMinute); $i++) {
            $wait = max(1, RateLimiter::availableIn($key));
            if ($i >= self::MAX_ATTEMPTS || $wait > $this->config['max_retry_wait']) {
                throw new OrdermentumException("Client-side Ordermentum limit for {$method} {$host} ({$perMinute}/min); retry in {$wait}s.", 429, $wait);
            }
            Sleep::for($wait)->seconds();
        }
        RateLimiter::hit($key, 60);
    }

    /** @return array{0: string, 1: int} limiter key, requests per minute */
    private function limitFor(string $host, string $method, string $path): array
    {
        $limits = $this->config['rate_limits'] ?? [];
        // Most specific first: "core:GET:/v1/orders", then "core:GET".
        foreach ($limits as $group => $perMinute) {
            [$h, $m, $prefix] = array_pad(explode(':', $group, 3), 3, null);
            if ($h === $host && $m === $method && $prefix !== null && str_starts_with('/'.ltrim($path, '/'), $prefix)) {
                return ["ordermentum:{$group}", (int) $perMinute];
            }
        }
        $group = "{$host}:{$method}";

        return ["ordermentum:{$group}", (int) ($limits[$group] ?? 0)];
    }

    private function request(): PendingRequest
    {
        $key = $this->config['api_key'] ?? null;
        if (blank($key)) {
            throw new OrdermentumException('ORDERMENTUM_API_KEY is not set.');
        }

        return Http::setHandler($this->handler)
            ->connectTimeout($this->config['connect_timeout'])
            ->timeout($this->config['timeout'])
            ->acceptJson()
            ->withHeaders(['x-api-key' => $key]);
    }

    private function url(string $baseKey, string $path): string
    {
        return rtrim($this->config[$baseKey], '/').'/'.ltrim($path, '/');
    }

    private function assertSafeContext(): void
    {
        if (! app()->runningInConsole()) {
            throw new LogicException('Ordermentum calls are not allowed during a web request; dispatch a job.');
        }
        // The transactions manager ignores a test's RefreshDatabase wrapper but sees every real transaction.
        $open = app()->bound('db.transactions')
            ? app('db.transactions')->callbackApplicableTransactions()->isNotEmpty()
            : DB::transactionLevel() > 0;
        if ($open) {
            throw new LogicException('Ordermentum calls are not allowed inside a database transaction.');
        }
    }
}
