<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'description',
    ];

    protected $casts = [
        'value' => 'string',
    ];

    public function getValueAttribute($value)
    {
        switch ($this->type) {
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'number':
                return (float) $value;
            case 'json':
                return json_decode($value, true);
            default:
                return $value;
        }
    }

    public function setValueAttribute($value)
    {
        if ($this->type === 'json') {
            $this->attributes['value'] = json_encode($value);
        } elseif ($this->type === 'boolean') {
            $this->attributes['value'] = $value ? '1' : '0';
        } else {
            $this->attributes['value'] = $value;
        }
    }
}