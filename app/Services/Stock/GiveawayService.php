<?php

namespace App\Services\Stock;

use App\Authorization\Permissions;
use App\Enums\MovementType;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Giveaway;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ReasonCode;
use App\Models\User;
use App\Services\Stock\Exceptions\InvalidMovement;
use Illuminate\Support\Facades\DB;

/**
 * Stock → Give away: samples, gifts, favours, staff and internal use, recorded in packs.
 * Each needs a recipient (a customer, or a name), a purpose and who approved it (someone
 * who may adjust stock). It's a write-off with a give-away reason, at today's average cost;
 * the ledger refuses give-away reasons from anywhere else.
 */
class GiveawayService
{
    public const SOURCE = 'giveaway';

    public function __construct(private StockLedger $ledger) {}

    public function record(
        User $actor,
        Product $product,
        ?ProductUnit $unit,
        float $packs,
        Location $bin,
        ?Batch $batch,
        ReasonCode $reason,
        ?Customer $customer,
        ?string $recipientName,
        string $purpose,
        User $approver,
        string $clientToken,
    ): Giveaway {
        $this->assert($reason->isGiveaway(), 'Choose sample, gift, favour, staff use or internal use.');
        $recipientName = trim((string) $recipientName) ?: null;
        $this->assert($customer !== null || $recipientName !== null, 'Who is it for? Choose a customer or type a name.');
        $this->assert(trim($purpose) !== '', 'Say what it is for.');
        $this->assert($approver->is_active && $approver->can(Permissions::STOCK_ADJUST), "{$approver->name} can't approve give-aways.");
        $this->assert($unit === null || $unit->product_id === $product->id, 'That pack is not for this product.');

        $per = max(1, (int) ($unit?->base_qty ?? 1));
        $qty = (int) round($packs * $per);
        $this->assert($qty > 0 && abs($qty - $packs * $per) < 0.0001, 'Enter a quantity that is a whole number of units.');

        return DB::transaction(function () use ($actor, $product, $unit, $packs, $per, $qty, $bin, $batch, $reason, $customer, $recipientName, $purpose, $approver, $clientToken) {
            $to = $customer?->name ?? $recipientName;
            $cost = $product->costAtNow();
            $movement = $this->ledger->postOne(new MovementDraft(
                product: $product,
                location: $bin,
                qty: -$qty,
                type: MovementType::WriteOff,
                idempotencyKey: "give:{$clientToken}",
                batch: $batch,
                reason: $reason,
                sourceType: self::SOURCE,
                unitCost: $cost,
                note: mb_substr("{$reason->name} to {$to}: ".trim($purpose)." (approved by {$approver->name})", 0, 1000),
            ), $actor);

            return Giveaway::firstOrCreate(['stock_movement_id' => $movement->id], [
                'reason_code_id' => $reason->id,
                'product_id' => $product->id,
                'product_unit_id' => $unit?->id,
                'base_qty_per_unit' => $per,
                'packs' => $packs,
                'qty_base' => $qty,
                'customer_id' => $customer?->id,
                'recipient_name' => $customer ? null : $recipientName,
                'purpose' => trim($purpose),
                'approved_by' => $approver->id,
                'created_by' => $actor->id,
                'unit_cost' => $cost,
                'value' => round($qty * (float) ($cost ?? 0), 2),
                'occurred_at' => $movement->occurred_at,
            ]);
        });
    }

    private function assert(bool $ok, string $message): void
    {
        if (! $ok) {
            throw new InvalidMovement($message);
        }
    }
}
