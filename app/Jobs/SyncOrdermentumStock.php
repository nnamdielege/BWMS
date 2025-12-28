<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class SyncOrdermentumStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300;

    private $warehouse_id;
    private $force;

    public function __construct($warehouse_id = null, $force = true)
    {
        $this->warehouse_id = $warehouse_id;
        $this->force = $force;
    }

    /**
     * Execute the job
     */
    public function handle()
    {
        Log::info('Starting async stock sync job', [
            'warehouse_id' => $this->warehouse_id,
            'force' => $this->force,
        ]);

        try {
            // Build command arguments
            $args = [];
            if ($this->warehouse_id) {
                $args['--warehouse'] = $this->warehouse_id;
            }
            if ($this->force) {
                $args['--force'] = true;
            }

            // Run sync:stock command
            $exitCode = Artisan::call('sync:stock', $args);

            if ($exitCode === 0) {
                Log::info('Stock sync job completed successfully');
            } else {
                Log::warning('Stock sync job completed with warnings', [
                    'exit_code' => $exitCode,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Stock sync job failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Handle failed job
     */
    public function failed(\Throwable $exception)
    {
        Log::error('Stock sync job failed after retries', [
            'exception' => $exception->getMessage(),
            'warehouse_id' => $this->warehouse_id,
        ]);
    }
}
