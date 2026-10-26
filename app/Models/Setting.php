<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value company settings (name, ABN, address… shown on POs). D-12.
 */
class Setting extends Model
{
    use Auditable;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public const COMPANY_KEYS = [
        'company.name', 'company.abn', 'company.address', 'company.phone', 'company.email',
        'company.delivery_address', 'po.terms',
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::all_()[$key] ?? $default;
    }

    /** @param  array<string, ?string>  $values */
    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            self::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Cache::forget('settings.all');
    }

    /** @return array<string, ?string> */
    public static function all_(): array
    {
        return Cache::remember('settings.all', 300, fn () => self::pluck('value', 'key')->all());
    }
}
