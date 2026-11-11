<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One raw inbound event (webhook or poll hit), stored before processing (NFR-5). */
class IntegrationInbox extends Model
{
    protected $table = 'integration_inbox';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_HELD = 'held';             // ingestion switched off; replay later

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_NEEDS_MAPPING = 'needs_mapping';

    public const STATUS_FAILED = 'failed';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_IGNORED_HISTORICAL = 'ignored_historical'; // delivered before ORDERMENTUM_INGEST_SINCE, not in BWMS

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['headers' => 'array', 'meta' => 'array', 'processed_at' => 'datetime'];
    }

    public function mark(string $status, ?string $error = null): void
    {
        $this->forceFill([
            'status' => $status,
            'error' => $error,
            'processed_at' => in_array($status, [self::STATUS_PROCESSED, self::STATUS_IGNORED, self::STATUS_IGNORED_HISTORICAL], true) ? now() : $this->processed_at,
        ])->save();
    }
}
