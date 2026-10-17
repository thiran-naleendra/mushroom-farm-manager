<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $product = $this->route('product');
        $type = $this->input('type', $product?->type ?? 'packaged');
        $this->merge(['type' => $type, 'unit' => $this->input('unit', $type === 'cultivation_bag' ? 'bag' : ($type === 'fresh' ? 'kg' : 'g'))]);
    }

    public function authorize(): bool
    {
        return $this->route('product') ? $this->user()->can('update', $this->route('product')) : $this->user()->can('create', Product::class);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:150', 'sku' => ['required', 'string', 'max:80', Rule::unique('products')->ignore($this->route('product'))],
            'variety' => 'required|string|max:100', 'type' => ['required', Rule::in(array_keys(Product::TYPES))],
            'pack_size_grams' => 'nullable|required_if:type,packaged|integer|min:1|max:1000000',
            'weight' => 'nullable|numeric|min:0.001|max:1000000|decimal:0,3',
            'unit' => ['required', Rule::in($this->input('type') === 'cultivation_bag' ? ['bag'] : ($this->input('type') === 'fresh' ? ['kg', 'g'] : ['g']))],
            'selling_price' => 'nullable|numeric|min:0|max:999999999.99|decimal:0,2',
            'is_active' => 'required|boolean', 'notes' => 'nullable|string|max:2000',
        ];
    }
}
