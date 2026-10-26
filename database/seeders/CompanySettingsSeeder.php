<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/** Seeds placeholders only; never overwrites values already set (D-12). */
class CompanySettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'company.name' => 'Local Selection',
            'company.abn' => '',
            'company.address' => '',
            'company.phone' => '',
            'company.email' => '',
            'company.delivery_address' => '',
            'po.terms' => '',
        ];

        foreach ($defaults as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
