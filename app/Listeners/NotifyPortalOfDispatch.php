<?php

namespace App\Listeners;

use App\Enums\OrderChannel;
use App\Events\OrderDispatched;
use App\Jobs\SendPortalOrderEvent;
use App\Models\Order;

/** OrderDispatched (after commit) -> one queued portal notification per portal order on the pick list. */
class NotifyPortalOfDispatch
{
    public function handle(OrderDispatched $event): void
    {
        if (! config('bwms.portal.events_enabled')) {
            return;
        }
        Order::whereIn('id', $event->orderIds)->where('channel', OrderChannel::Portal)->pluck('id')
            ->each(fn ($id) => SendPortalOrderEvent::dispatch($id, $event->pickListId));
    }
}
