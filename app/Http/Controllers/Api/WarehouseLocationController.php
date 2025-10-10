<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WarehouseLocation;
use Illuminate\Http\Request;

class WarehouseLocationController extends Controller
{
    public function index(Request $request)
    {
        $query = WarehouseLocation::with(['warehouse', 'parent']);

        if ($request->has('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->has('location_type')) {
            $query->where('location_type', $request->location_type);
        }

        $locations = $query->get();

        return response()->json($locations);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'location_code' => 'required|unique:warehouse_locations,location_code',
            'name' => 'required|string',
            'location_type' => 'required|in:zone,aisle,rack,shelf,bin',
            'parent_id' => 'nullable|exists:warehouse_locations,id',
            'capacity' => 'nullable|integer|min:0',
        ]);

        $location = WarehouseLocation::create($validated);

        return response()->json($location, 201);
    }

    public function show(WarehouseLocation $warehouseLocation)
    {
        return response()->json($warehouseLocation->load(['warehouse', 'parent', 'children']));
    }

    public function update(Request $request, WarehouseLocation $warehouseLocation)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string',
            'capacity' => 'nullable|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $warehouseLocation->update($validated);

        return response()->json($warehouseLocation);
    }

    public function destroy(WarehouseLocation $warehouseLocation)
    {
        $warehouseLocation->delete();
        return response()->json(['message' => 'Location deleted successfully']);
    }
}