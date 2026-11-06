<?php

namespace App\Services\Catalogue\Import;

use Illuminate\Support\Facades\Cache;

/**
 * An import preview between steps, per user and token, for 30 minutes like the
 * cost import. Too big for the session, and the queued Ordermentum fetch has to be
 * able to write it.
 */
class ImportPreviewStore
{
    public const MINUTES = 30;

    public function __construct(private ProductImportPlanner $planner) {}

    public function fetching(int $userId, string $token, string $source): void
    {
        $this->save($userId, $token, ['status' => 'fetching', 'source' => $source, 'expires_at' => now()->addMinutes(self::MINUTES)->timestamp]);
    }

    public function failed(int $userId, string $token, string $source, string $error): void
    {
        $this->save($userId, $token, ['status' => 'failed', 'source' => $source, 'error' => $error, 'expires_at' => now()->addMinutes(self::MINUTES)->timestamp]);
    }

    /** Plans the items and keeps them for apply. Each change of edits plans again, on the data as it is then. */
    public function ready(int $userId, string $token, string $source, array $items, array $edits = [], ?int $expiresAt = null): void
    {
        $rows = $this->planner->plan($items, $edits);
        $this->save($userId, $token, [
            'status' => 'ready', 'source' => $source, 'items' => $items, 'edits' => $edits,
            'rows' => $this->planner->forDisplay($rows), 'summary' => $this->planner->summary($rows),
            'expires_at' => $expiresAt ?? now()->addMinutes(self::MINUTES)->timestamp,
        ]);
    }

    public function get(int $userId, string $token): ?array
    {
        return Cache::get($this->key($userId, $token));
    }

    /** Taken out whole, so a double submit finds nothing. */
    public function pull(int $userId, string $token): ?array
    {
        return Cache::pull($this->key($userId, $token));
    }

    /** Puts a pulled preview back, for an apply that was refused. */
    public function restore(int $userId, string $token, array $preview): void
    {
        $this->save($userId, $token, $preview);
    }

    private function save(int $userId, string $token, array $preview): void
    {
        Cache::put($this->key($userId, $token), $preview, now()->setTimestamp($preview['expires_at']));
    }

    private function key(int $userId, string $token): string
    {
        return "product-import:{$userId}:{$token}";
    }
}
