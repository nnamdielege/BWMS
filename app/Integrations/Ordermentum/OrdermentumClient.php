<?php

namespace App\Integrations\Ordermentum;

use GuzzleHttp\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use LogicException;

/**
 * The one HTTP client for Ordermentum (NFR: shared client, timeouts, rate
 * limits). Registered as a singleton and holding one curl handler, so
 * connections and DNS lookups are reused across calls.
 *
 * Only for console commands and queued jobs: it refuses to run during a web
 * request or inside a DB transaction.
 *
 * The only write is putStock (absolute stock level); om:probe uses GETs only.
 */
class OrdermentumClient
{
    private const TOKEN_CACHE_KEY = 'ordermentum:bearer';

    private const MAX_ATTEMPTS = 3;

    /** @var callable */
    private $handler;

    public function __construct(private array $config)
    {
        $this->handler = Utils::chooseHandler();
    }

    public function authMode(): string
    {
        return filled($this->config['api_key'] ?? null) ? 'api_key' : 'password';
    }

    /** GET on the core API (api.ordermentum.com). */
    public function get(string $path, array $query = []): Response
    {
        return $this->send(fn () => $this->request()->get($this->url('base_url', $path), $query));
    }

    /** GET on the stock API (stock.ordermentum.com); adds the supplier context headers. */
    public function getStock(string $path, array $query = [], string $contextHeaders = 'both'): Response
    {
        return $this->send(fn () => $this->request()
            ->withHeaders($this->contextHeaders($contextHeaders))
            ->get($this->url('stock_url', $path), $query));
    }

    /** Absolute stock set: PUT /v1/items/{variantId} on the stock API. Retries are naturally idempotent. */
    public function putStock(string $path, array $body, string $contextHeaders = 'both'): Response
    {
        return $this->send(fn () => $this->request()
            ->withHeaders($this->contextHeaders($contextHeaders))
            ->put($this->url('stock_url', $path), $body));
    }

    /**
     * The docs name the stock context headers both `context-entity`/`context-id`
     * and `x-context-entity`/`x-context-id` (spec B3.1). 'both' sends all four.
     *
     * @return array<string, string>
     */
    public function contextHeaders(string $which = 'both'): array
    {
        $supplierId = $this->config['supplier_id'] ?? null;
        if (blank($supplierId)) {
            throw new OrdermentumException('ORDERMENTUM_SUPPLIER_ID is not set; the stock API needs it.');
        }
        $plain = ['context-entity' => 'supplier', 'context-id' => (string) $supplierId];
        $x = ['x-context-entity' => 'supplier', 'x-context-id' => (string) $supplierId];

        return match ($which) {
            'plain' => $plain,
            'x' => $x,
            'both' => $plain + $x,
            default => throw new LogicException("Unknown context header set [{$which}]."),
        };
    }

    /** @param  callable(): Response  $call */
    private function send(callable $call): Response
    {
        $this->assertSafeContext();

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $call();
            } catch (ConnectionException $e) {
                throw new OrdermentumException('Ordermentum unreachable: '.$e->getMessage());
            }

            if ($response->status() === 401 && $this->authMode() === 'password' && $attempt === 1) {
                Cache::forget(self::TOKEN_CACHE_KEY); // expired token: log in again once

                continue;
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

    private function request(): PendingRequest
    {
        $request = Http::setHandler($this->handler)
            ->connectTimeout($this->config['connect_timeout'])
            ->timeout($this->config['timeout'])
            ->acceptJson();

        return $this->authMode() === 'api_key'
            ? $request->withHeaders(['x-api-key' => $this->config['api_key']])
            : $request->withToken($this->bearerToken());
    }

    /** Legacy username/password login (deprecated 1 Jan 2027, R-1). Token cached encrypted for 23h. */
    private function bearerToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if ($cached) {
            return Crypt::decryptString($cached);
        }

        if (blank($this->config['username'] ?? null) || blank($this->config['password'] ?? null)) {
            throw new OrdermentumException('No Ordermentum credentials: set ORDERMENTUM_API_KEY, or ORDERMENTUM_USERNAME and ORDERMENTUM_PASSWORD.');
        }

        try {
            $response = Http::setHandler($this->handler)
                ->connectTimeout($this->config['connect_timeout'])
                ->timeout($this->config['timeout'])
                ->acceptJson()
                ->post($this->config['auth_url'], ['username' => $this->config['username'], 'password' => $this->config['password']]);
        } catch (ConnectionException $e) {
            throw new OrdermentumException('Ordermentum login unreachable: '.$e->getMessage());
        }

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            // Never echo the response body: it may contain credential details.
            throw new OrdermentumException("Ordermentum login failed (HTTP {$response->status()}).", $response->status());
        }

        Cache::put(self::TOKEN_CACHE_KEY, Crypt::encryptString($token), now()->addHours(23));

        return $token;
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
