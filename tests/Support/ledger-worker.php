<?php

/*
 * Separate-process worker for StockLedgerConcurrencyTest. Boots the app against
 * the testing database (env passed by the test), waits for a shared start time
 * so all workers hit the ledger together, runs one scenario, prints JSON.
 *
 *   php ledger-worker.php <scenario> <json-args>
 */

use App\Enums\MovementType;
use App\Enums\OrderChannel;
use App\Models\Location;
use App\Models\Product;
use App\Services\Orders\ChannelOrderData;
use App\Services\Orders\OrderService;
use App\Services\Stock\Exceptions\StockException;
use App\Services\Stock\MovementDraft;
use App\Services\Stock\StockLedger;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$scenario, $json] = [$argv[1], $argv[2]];
$args = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

// Barrier: start together to maximise contention.
while (microtime(true) < $args['start_at']) {
    usleep(500);
}

$ledger = app(StockLedger::class);
$product = Product::findOrFail($args['product_id']);
$draft = fn (int $locationId, int $qty, MovementType $type, string $key, bool $allowNegative = false) => new MovementDraft(
    product: $product, location: Location::findOrFail($locationId), qty: $qty, type: $type,
    idempotencyKey: $key, allowNegative: $allowNegative,
);

$results = [];
try {
    switch ($scenario) {
        case 'withdraw':
            // One sale of `qty`, unique key. Must fail cleanly if stock is short.
            $ledger->post([$draft($args['location_id'], -$args['qty'], MovementType::Sale, $args['key'])]);
            $results[] = 'ok';
            break;

        case 'same-key':
            // Everyone posts the identical movement with the same key.
            $id = $ledger->postOne($draft($args['location_id'], $args['qty'], MovementType::Receipt, $args['key']))->id;
            $results[] = "ok:{$id}";
            break;

        case 'mixed':
            // Many small ins and outs with unique keys; negatives allowed so none fail.
            mt_srand($args['seed']);
            for ($i = 0; $i < $args['iterations']; $i++) {
                $in = mt_rand(0, 1) === 1;
                $qty = mt_rand(1, 5);
                $ledger->post([$draft($args['location_id'], $in ? $qty : -$qty, $in ? MovementType::Receipt : MovementType::Sale, "{$args['key']}:{$i}", true)]);
                $results[] = 'ok';
            }
            break;

        case 'reserve':
            // A portal order for `qty`; must be refused cleanly when availability runs out.
            app(OrderService::class)->upsertFromChannel(new ChannelOrderData(
                channel: OrderChannel::Portal, externalRef: $args['key'], version: 1, status: 'confirmed',
                siteId: $args['site_id'],
                lines: [['line_ref' => '1', 'product_id' => $product->id, 'product_unit_id' => $args['unit_id'], 'qty' => $args['qty']]],
            ));
            $results[] = 'ok';
            break;

        case 'crossing-transfers':
            // A->B and B->A in parallel: two-slot postings in opposite directions.
            for ($i = 0; $i < $args['iterations']; $i++) {
                [$from, $to] = $args['direction'] === 'ab' ? [$args['a'], $args['b']] : [$args['b'], $args['a']];
                $ledger->post([
                    $draft($from, -1, MovementType::TransferOut, "{$args['key']}:{$i}:out", true),
                    $draft($to, 1, MovementType::TransferIn, "{$args['key']}:{$i}:in", true),
                ]);
                $results[] = 'ok';
            }
            break;
    }
} catch (StockException $e) {
    $results[] = 'rejected:'.class_basename($e);
} catch (Throwable $e) {
    $results[] = 'error:'.get_class($e).': '.$e->getMessage();
}

echo json_encode($results);
