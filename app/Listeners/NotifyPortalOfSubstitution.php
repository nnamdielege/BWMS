<?php

namespace App\Listeners;

use App\Enums\OrderChannel;
use App\Events\OrderSubstitutionChanged;
use App\Jobs\SendPortalSubstitutionEvent;
use App\Models\OrderSubstitution;

/** OrderSubstitutionChanged (after commit) -> one queued portal notification, for a portal order. */
class NotifyPortalOfSubstitution
{
    public function handle(OrderSubstitutionChanged $event): void
    {
        if (! config('bwms.portal.events_enabled')) {
            return;
        }
        if (OrderSubstitution::whereKey($event->substitutionId)->whereHas('order', fn ($q) => $q->where('channel', OrderChannel::Portal))->exists()) {
            SendPortalSubstitutionEvent::dispatch($event->substitutionId, $event->kind);
        }
    }
}
