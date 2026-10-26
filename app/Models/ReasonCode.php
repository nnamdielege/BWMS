<?php

namespace App\Models;

use App\Enums\MovementType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class ReasonCode extends Model
{
    use Auditable;

    protected $fillable = ['code', 'name', 'movement_type', 'direction', 'requires_note', 'is_active'];

    protected function casts(): array
    {
        return [
            'movement_type' => MovementType::class,
            'requires_note' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function allowsQty(int $qty): bool
    {
        return match ($this->direction) {
            'in' => $qty > 0,
            'out' => $qty < 0,
            default => true,
        };
    }
}
