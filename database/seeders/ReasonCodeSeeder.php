<?php

namespace Database\Seeders;

use App\Models\ReasonCode;
use Illuminate\Database\Seeder;

/**
 * Idempotent. Codes are referenced by movements, so existing codes are updated
 * in place rather than deleted.
 */
class ReasonCodeSeeder extends Seeder
{
    public function run(): void
    {
        $codes = [
            ['code' => 'DAMAGED', 'name' => 'Damaged / broken', 'movement_type' => 'write_off', 'direction' => 'out', 'requires_note' => false],
            ['code' => 'EXPIRED', 'name' => 'Past best-before', 'movement_type' => 'write_off', 'direction' => 'out', 'requires_note' => false],
            ['code' => 'SPOILED', 'name' => 'Spoiled (cold chain / quality)', 'movement_type' => 'write_off', 'direction' => 'out', 'requires_note' => true],
            ['code' => 'SAMPLE', 'name' => 'Samples / tastings', 'movement_type' => 'write_off', 'direction' => 'out', 'requires_note' => false],
            ['code' => 'INTERNAL', 'name' => 'Internal use', 'movement_type' => 'write_off', 'direction' => 'out', 'requires_note' => true],
            ['code' => 'FOUND', 'name' => 'Found stock', 'movement_type' => 'adjustment', 'direction' => 'in', 'requires_note' => true],
            ['code' => 'LOST', 'name' => 'Lost / missing', 'movement_type' => 'adjustment', 'direction' => 'out', 'requires_note' => true],
            ['code' => 'CORRECTION', 'name' => 'Data-entry correction', 'movement_type' => 'adjustment', 'direction' => 'both', 'requires_note' => true],
        ];

        foreach ($codes as $code) {
            ReasonCode::updateOrCreate(['code' => $code['code']], $code + ['is_active' => true]);
        }
    }
}
