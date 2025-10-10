<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => 'required|string|max:255|unique:products,sku',
            'barcode' => 'nullable|string|max:255|unique:products,barcode',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'required|exists:product_categories,id',
            'cost' => 'required|numeric|min:0',
            'price' => 'required|numeric|min:0',
            'unit_of_measure' => 'required|string|max:50',
            'reorder_point' => 'nullable|integer|min:0',
            'reorder_quantity' => 'nullable|integer|min:0',
            'weight' => 'nullable|numeric|min:0',
            'dimensions' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ];
    }

    protected function prepareForValidation()
    {
        // Convert empty strings to null
        $this->merge([
            'barcode' => $this->barcode === '' ? null : $this->barcode,
            'description' => $this->description === '' ? null : $this->description,
            'reorder_point' => $this->reorder_point === '' ? null : $this->reorder_point,
            'reorder_quantity' => $this->reorder_quantity === '' ? null : $this->reorder_quantity,
            'weight' => $this->weight === '' ? null : $this->weight,
            'dimensions' => $this->dimensions === '' ? null : $this->dimensions,
            'notes' => $this->notes === '' ? null : $this->notes,
        ]);
    }
}