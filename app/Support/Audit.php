<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes audit entries. Model CRUD is recorded automatically by the Auditable
 * trait; call Audit::record() for domain events (login, role change, invite...).
 */
final class Audit
{
    private static bool $modelEventsMuted = false;

    /**
     * Run a bulk writer that records one summary entry per subject itself, so the
     * Auditable trait stays out of it. Audit::record() still writes.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function withoutModelEvents(callable $work): mixed
    {
        $before = self::$modelEventsMuted;
        self::$modelEventsMuted = true;
        try {
            return $work();
        } finally {
            self::$modelEventsMuted = $before;
        }
    }

    public static function modelEventsMuted(): bool
    {
        return self::$modelEventsMuted;
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @param  array<string, mixed>|null  $meta
     */
    public static function record(string $event, ?Model $subject = null, ?array $old = null, ?array $new = null, ?array $meta = null, ?int $userId = null): AuditLog
    {
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::create([
            'user_id' => $userId ?? Auth::id(),
            'event' => $event,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'meta' => $meta ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 512) : null,
        ]);
    }
}
