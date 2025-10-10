<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bin;
use Illuminate\Http\Request;

class BinController extends Controller
{
    public function index(Request $request)
    {
        $query = Bin::with('warehouse');

        if ($request->has('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        $bins = $query->get();

        return response()->json($bins);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'bin_code' => 'required|unique:bins,bin_code',
            'aisle' => 'nullable|string',
            'rack' => 'nullable|string',
            'level' => 'nullable|string',
            'position' => 'nullable|string',
            'capacity' => 'nullable|integer|min:0',
        ]);

        $bin = Bin::create($validated);

        return response()->json($bin, 201);
    }

    public function show(Bin $bin)
    {
        return response()->json($bin->load('warehouse'));
    }

    public function update(Request $request, Bin $bin)
    {
        $validated = $request->validate([
            'capacity' => 'nullable|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $bin->update($validated);

        return response()->json($bin);
    }

    public function destroy(Bin $bin)
    {
        $bin->delete();
        return response()->json(['message' => 'Bin deleted successfully']);
    }
}