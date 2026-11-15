<?php

/*
 * Separate-process worker for PurchaseOrderNumberConcurrencyTest. Boots the app
 * against the testing database, waits for a shared start time, creates
 * `count` draft POs through PurchaseOrderService and prints their numbers as JSON.
 *
 *   php po-number-worker.php <json-args>
 */

use App\Models\User;
use App\Services\Purchasing\PurchaseOrderService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
while (microtime(true) < $args['start_at']) {
    usleep(500);
}

$results = [];
try {
    $user = User::findOrFail($args['user_id']);
    for ($i = 0; $i < $args['count']; $i++) {
        $results[] = app(PurchaseOrderService::class)->saveDraft(null, $args['data'], $user)->number;
    }
} catch (Throwable $e) {
    $results[] = 'error:'.get_class($e).': '.$e->getMessage();
}

echo json_encode($results);
