<?php

namespace App\Jobs;

use App\Events\OrderSubstitutionChanged;
use App\Models\ChannelListing;
use App\Models\Order;
use App\Models\OrderSubstitution;

/**
 * Staff substituted a line of a portal order (or undid it). The body is built from the
 * substitution record, which never changes once made, so a retry says the same thing.
 * One key per substitution and kind.
 */
class SendPortalSubstitutionEvent extends SendsPortalOrderEvent
{
    public function __construct(public int $substitutionId, public string $kind) {}

    public static function payload(OrderSubstitution $s, string $kind): array
    {
        $s->loadMissing(['order', 'originalLine', 'substituteProduct', 'substituteUnit']);
        $portalId = ChannelListing::where('channel', 'portal')->where('product_id', $s->substitute_product_id)
            ->orderByRaw('product_unit_id = ? DESC', [$s->substitute_unit_id])->value('external_product_id');
        $at = ($kind === OrderSubstitutionChanged::UNDONE ? $s->undone_at : $s->created_at)->utc()->format('Y-m-d\TH:i:s\Z');

        return [
            'order_ref' => $s->order->external_ref,
            'event' => $kind,
            'substitution_id' => $s->id,
            'original' => [
                'line_ref' => $s->originalLine->line_ref,
                // Sell units of the original cancelled by this substitution (put back, on an undo).
                'qty' => self::packs($s->original_qty_base, $s->original_per),
                'unit_price' => $s->original_unit_price,
            ],
            'substitute' => [
                'line_ref' => $s->substitute_line_ref,
                'portal_product_id' => $portalId !== null ? (int) $portalId : null,
                'sku' => $s->substituteProduct->sku,
                'name' => $s->substituteProduct->name,
                'unit' => $s->substituteUnit->name,
                'unit_quantity' => $s->substitute_per,
                'qty' => self::packs($s->substitute_qty_base, $s->substitute_per),
                'unit_price' => $s->substitute_unit_price,
            ],
            'price_difference' => $s->price_difference,
            'at' => $at,
        ];
    }

    protected function order(): ?Order
    {
        return OrderSubstitution::find($this->substitutionId)?->order;
    }

    protected function body(Order $order): ?array
    {
        $s = OrderSubstitution::find($this->substitutionId);
        if (! $s || ($this->kind === OrderSubstitutionChanged::UNDONE && ! $s->undone_at)) {
            return null;
        }

        return self::payload($s, $this->kind);
    }

    protected function idempotencyKey(Order $order): string
    {
        return "bwms:order-event:{$order->external_ref}:{$this->kind}:{$this->substitutionId}";
    }
}
