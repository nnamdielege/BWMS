<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('categories')->where('parent_id', $request->input('parent_id'))],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        Category::create($data);

        return back()->with('success', "Category {$data['name']} added.");
    }
}
