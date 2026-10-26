<?php

namespace App\Http\Controllers;

use App\Models\Range;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RangeController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', 'unique:ranges,name']]);

        Range::create($data);

        return back()->with('success', "Range {$data['name']} added.");
    }
}
