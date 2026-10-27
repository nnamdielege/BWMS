<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\Reservation;
use App\Models\StockBalance;
use App\Rules\Gtin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Product with its pack sizes and barcodes, saved as one unit of work.
 * Authorization is done by route `can:` middleware.
 */
class ProductRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'sku' => strtoupper(trim((string) $this->input('sku'))),
            'units' => collect($this->input('units', []))->map(fn ($u) => [
                ...$u,
                'barcodes' => collect($u['barcodes'] ?? [])->map(fn ($b) => trim((string) $b))->filter()->values()->all(),
            ])->all(),
        ]);
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'sku' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9._\-\/]*$/', Rule::unique('products', 'sku')->ignore($productId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'range_id' => ['nullable', 'integer', 'exists:ranges,id'],
            'base_unit' => ['required', 'string', 'max:32'],
            'is_batch_tracked' => ['boolean'],
            'shelf_life_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'short_dated_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'reorder_point' => ['nullable', 'integer', 'min:0'],
            'reorder_qty' => ['nullable', 'integer', 'min:0'],
            'sell_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'is_active' => ['boolean'],
            'sell_channels' => ['sometimes', Rule::in(Product::SELL_CHANNELS)],
            'is_stock_tracked' => ['sometimes', 'boolean'],
            'is_chilled' => ['sometimes', 'boolean'],

            'units' => ['required', 'array', 'min:1', 'max:20'],
            'units.*.id' => ['nullable', 'integer'],
            'units.*.name' => ['required', 'string', 'max:64', 'distinct:ignore_case'],
            'units.*.code' => ['nullable', 'string', 'max:64', 'distinct'],
            'units.*.base_qty' => ['required', 'integer', 'min:1', 'max:100000', 'distinct'],
            'units.*.purchasable' => ['boolean'],
            'units.*.sellable' => ['boolean'],
            'units.*.default_purchase' => ['boolean'],
            'units.*.default_sale' => ['boolean'],
            'units.*.barcodes' => ['array', 'max:10'],
            'units.*.barcodes.*' => ['string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/', 'distinct', new Gtin],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $units = collect($this->input('units', []));

            if ($units->where('base_qty', 1)->count() !== 1) {
                $validator->errors()->add('units', 'Exactly one pack size must have a quantity of 1 (the base unit).');
            }
            if ($units->where('default_purchase', true)->count() > 1) {
                $validator->errors()->add('units', 'Only one pack size can be the default purchase unit.');
            }
            if ($units->where('default_sale', true)->count() > 1) {
                $validator->errors()->add('units', 'Only one pack size can be the default sale unit.');
            }
            // A product holding stock or reservations cannot stop being stock-tracked.
            $product = $this->route('product');
            if ($product && $this->has('is_stock_tracked') && ! $this->boolean('is_stock_tracked') && $product->is_stock_tracked
                && (StockBalance::where('product_id', $product->id)->where('on_hand', '!=', 0)->exists()
                    || Reservation::where('product_id', $product->id)->where('qty_base', '>', 0)->exists())) {
                $validator->errors()->add('is_stock_tracked', 'This product has stock or reservations; clear them before marking it not stock-tracked.');
            }
            // Barcodes must be unique across all units, including within this request.
            $all = $units->pluck('barcodes')->flatten();
            if ($all->count() !== $all->unique()->count()) {
                $validator->errors()->add('units', 'The same barcode is used on more than one pack size.');
            }
        }];
    }
}
