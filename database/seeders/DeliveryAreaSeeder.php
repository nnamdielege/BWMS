<?php

namespace Database\Seeders;

use App\Models\DeliveryArea;
use Illuminate\Database\Seeder;

/**
 * Delivery areas and weekdays (decided 2026-10-27):
 *   Mon North of Brisbane · Tue Gold Coast · Wed Sunshine Coast ·
 *   Thu Ipswich + western suburbs · Fri CBD/Valley/West End/southern suburbs/Redlands/Wynnum/Carindale.
 *   Northern Zones 1-3 (Peninsula, Moreton Bay inner, Moreton Bay outer) also get next-day delivery on any weekday.
 *
 * The postcode lists are a STARTER set to be reviewed in Settings -> Delivery areas.
 * Places are only seeded when an area is first created, so this seeder (run on
 * every deploy) never overwrites edits. Unmatched addresses leave the order
 * unassigned for manual assignment.
 */
class DeliveryAreaSeeder extends Seeder
{
    public const AREAS = [
        ['code' => 'NORTH', 'name' => 'North of Brisbane', 'weekdays' => [1], 'next_day' => false,
            'postcodes' => ['4012', '4013', '4014', '4017', '4018', '4030', '4031', '4032', '4034', '4035', '4036', '4037', '4051', '4053', '4054', '4055']],
        ['code' => 'ZONE1', 'name' => 'Zone 1 · Peninsula', 'weekdays' => [1], 'next_day' => true,
            'postcodes' => ['4019', '4020', '4021', '4022']],
        ['code' => 'ZONE2', 'name' => 'Zone 2 · Moreton Bay inner', 'weekdays' => [1], 'next_day' => true,
            'postcodes' => ['4500', '4501', '4502', '4503', '4509']],
        ['code' => 'ZONE3', 'name' => 'Zone 3 · Moreton Bay outer', 'weekdays' => [1], 'next_day' => true,
            'postcodes' => ['4504', '4505', '4506', '4507', '4508', '4510']],
        ['code' => 'GOLDCOAST', 'name' => 'Gold Coast', 'weekdays' => [2], 'next_day' => false,
            'range' => [4209, 4230]],
        ['code' => 'SUNCOAST', 'name' => 'Sunshine Coast', 'weekdays' => [3], 'next_day' => false,
            'postcodes' => ['4519'], 'range' => [4550, 4575]],
        ['code' => 'WEST', 'name' => 'Ipswich & western suburbs', 'weekdays' => [4], 'next_day' => false,
            'postcodes' => ['4300', '4301', '4303', '4304', '4305', '4306', '4064', '4065', '4066', '4067', '4068', '4069', '4070', '4073', '4074', '4075', '4076', '4077', '4078']],
        ['code' => 'CBDSOUTH', 'name' => 'CBD, Valley, West End, south, Redlands, Wynnum, Carindale', 'weekdays' => [5], 'next_day' => false,
            'postcodes' => ['4000', '4005', '4006', '4101', '4102', '4103', '4104', '4105', '4106', '4107', '4108', '4109', '4110', '4111', '4112', '4113', '4115', '4116',
                '4120', '4121', '4122', '4151', '4152', '4169', '4170', '4171', '4172', '4173', '4174', '4178', '4179',
                '4157', '4158', '4159', '4160', '4161', '4163', '4164', '4165']],
    ];

    public function run(): void
    {
        foreach (self::AREAS as $i => $a) {
            $area = DeliveryArea::firstOrCreate(['code' => $a['code']], [
                'name' => $a['name'], 'weekdays' => $a['weekdays'], 'next_day_eligible' => $a['next_day'], 'is_active' => true, 'sort_order' => $i,
            ]);
            if (! $area->wasRecentlyCreated) {
                continue; // never overwrite edited lists
            }
            $postcodes = [...($a['postcodes'] ?? []), ...(isset($a['range']) ? array_map('strval', range($a['range'][0], $a['range'][1])) : [])];
            foreach ($postcodes as $pc) {
                $area->places()->firstOrCreate(['postcode' => $pc, 'suburb' => '']);
            }
        }
    }
}
