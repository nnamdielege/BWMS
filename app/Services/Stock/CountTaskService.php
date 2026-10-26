<?php

namespace App\Services\Stock;

use App\Models\CountTask;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stocktake;
use App\Models\User;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class CountTaskService
{
    public function __construct(private StockQuery $stock) {}

    /**
     * Opens a count task for the bin, or bumps the open one (one per bin).
     * Safe inside the caller's transaction: a single atomic statement.
     */
    public function raise(Location $bin, ?Product $product, string $reason, ?string $sourceType, ?int $sourceId, ?int $onHand): void
    {
        $now = now();
        DB::statement(
            'INSERT INTO count_tasks (location_id, product_id, status, reason, source_type, source_id, on_hand_at_raise, times_raised, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE times_raised = times_raised + 1, updated_at = VALUES(updated_at)',
            [$bin->id, $product?->id, CountTask::STATUS_OPEN, $reason, $sourceType, $sourceId, $onHand, $now, $now],
        );
    }

    /** Called when a stocktake posts: every open task for a bin it covered is done. */
    public function closeForStocktake(Stocktake $stocktake, User $actor): int
    {
        return CountTask::where('status', CountTask::STATUS_OPEN)
            ->whereIn('location_id', $this->stock->binIdsUnder($stocktake->scope))
            ->update([
                'status' => CountTask::STATUS_DONE, 'stocktake_id' => $stocktake->id,
                'resolved_by' => $actor->id, 'resolved_at' => now(), 'updated_at' => now(),
            ]);
    }

    public function dismiss(CountTask $task, User $actor, string $note): CountTask
    {
        if ($task->status !== CountTask::STATUS_OPEN) {
            throw new InvalidMovement('This count task is already closed.');
        }
        $task->forceFill([
            'status' => CountTask::STATUS_DISMISSED, 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_note' => $note,
        ])->save();
        Audit::record('count_task_dismissed', $task->location, meta: ['count_task_id' => $task->id, 'note' => $note]);

        return $task;
    }
}
