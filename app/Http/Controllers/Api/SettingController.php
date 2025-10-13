<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SettingController extends Controller
{
    public function index(Request $request)
    {
        try {
            $group = $request->get('group');

            $query = Setting::query();

            if ($group) {
                $query->where('group', $group);
            }

            $settings = $query->get()->groupBy('group');

            return response()->json($settings);
        } catch (\Exception $e) {
            Log::error('Fetch settings error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($key)
    {
        try {
            $setting = Setting::where('key', $key)->firstOrFail();

            return response()->json($setting);
        } catch (\Exception $e) {
            Log::error('Fetch setting error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Setting not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'required',
        ]);

        DB::beginTransaction();

        try {
            foreach ($validated['settings'] as $settingData) {
                Setting::updateOrCreate(
                    ['key' => $settingData['key']],
                    ['value' => $settingData['value']]
                );
            }

            DB::commit();

            return response()->json([
                'message' => 'Settings updated successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Settings update failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to update settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'key' => 'required|string|unique:settings,key',
            'value' => 'required',
            'type' => 'required|in:string,number,boolean,json',
            'group' => 'required|string',
            'description' => 'nullable|string',
        ]);

        try {
            $setting = Setting::create($validated);

            return response()->json([
                'message' => 'Setting created successfully',
                'data' => $setting
            ], 201);
        } catch (\Exception $e) {
            Log::error('Setting creation failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to create setting',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function displaySettings()
    {
        // You can return all, or specific settings
        $settings = Setting::whereIn('key', ['default_tax_rate', 'order_prefix_purchase'])->pluck('value', 'key');

        return response()->json($settings);
    }
}