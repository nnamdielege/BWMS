<?php

/**
 * Small lookups for capture.cjs (demo database only). Prints one value.
 *
 *   php docs/manual/tools/lookup.php invite <email>          new invitation token
 *   php docs/manual/tools/lookup.php id <table> <column> <value>
 *   php docs/manual/tools/lookup.php value <table> <column> <value> <wanted>
 */

use App\Models\UserInvitation;
use App\Support\DatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DatabaseSafety::assertDestructiveAllowed('Manual screenshot lookups');

$args = array_slice($argv, 1);
$allowed = ['products', 'purchase_orders', 'orders', 'customers', 'stocktakes', 'pick_lists', 'locations'];

echo match ($args[0] ?? '') {
    'invite' => UserInvitation::issue($args[1], 'Drew Example', 'warehouse_staff', null)[1],
    'id', 'value' => in_array($args[1], $allowed, true)
        ? DB::table($args[1])->where($args[2], $args[3])->value($args[0] === 'id' ? 'id' : $args[4])
        : throw new InvalidArgumentException('Unknown table'),
    default => throw new InvalidArgumentException('Usage: invite|id|value'),
};
