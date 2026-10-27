<?php

namespace App\Integrations\Portal;

use GuzzleHttp\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use RuntimeException;

/**
 * The one HTTP client for BWMS -> portal calls (spec B4.4, NFR-1/2). Signs
 * every request, uses 5s connect / 15s total timeouts and one reused curl
 * handler. Only for queued jobs and console: refuses web requests and open
 * DB transactions. The shared secret is never logged.
 */
class PortalClient
{
    /** @var callable */
    private $handler;

    public function __construct(private array $config)
    {
        $this->handler = Utils::chooseHandler();
    }

    /** @param  list<array{portal_product_id: int, available: int, as_of: string}>  $items */
    public function putStock(array $items): Response
    {
        return $this->send('PUT', '/api/bwms/v1/stock', $items);
    }

    public function postOrderEvent(array $event, string $idempotencyKey): Response
    {
        return $this->send('POST', '/api/bwms/v1/order-events', $event, ['Idempotency-Key' => $idempotencyKey]);
    }

    private function send(string $method, string $path, array $payload, array $headers = []): Response
    {
        if (! app()->runningInConsole()) {
            throw new LogicException('Portal calls are not allowed during a web request; dispatch a job.');
        }
        $open = app()->bound('db.transactions') ? app('db.transactions')->callbackApplicableTransactions()->isNotEmpty() : DB::transactionLevel() > 0;
        if ($open) {
            throw new LogicException('Portal calls are not allowed inside a database transaction.');
        }
        foreach (['base_url', 'outbound_key_id', 'outbound_secret'] as $key) {
            if (blank($this->config[$key] ?? null)) {
                throw new RuntimeException('Portal outbound is not configured (PORTAL_'.strtoupper($key).').');
            }
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        try {
            return Http::setHandler($this->handler)
                ->connectTimeout($this->config['connect_timeout'])
                ->timeout($this->config['timeout'])
                ->acceptJson()
                ->withHeaders($headers + PortalSignature::headers($this->config['outbound_key_id'], $this->config['outbound_secret'], $body))
                ->withBody($body, 'application/json')
                ->send($method, rtrim($this->config['base_url'], '/').$path);
        } catch (ConnectionException $e) {
            throw new PortalUnavailable('Portal unreachable: '.$e->getMessage());
        }
    }
}
