<?php

namespace App\Http\Requests;

use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('material') ? $this->user()->can('update', $this->route('material')) : $this->user()->can('create', Material::class);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'sku' => ['required', 'string', 'max:60', Rule::unique('materials')->ignore($this->route('material'))],
            'material_category_id' => 'required|integer|exists:material_categories,id',
            'material_unit_id' => 'required|integer|exists:material_units,id',
            'supply_type' => 'required|in:growing,retail',
            'unit_cost' => 'required|numeric|min:0|max:999999999.99|decimal:0,2',
            'minimum_stock' => 'required|numeric|min:0|max:999999999.999999|decimal:0,6',
            'is_active' => 'required|boolean', 'notes' => 'nullable|string|max:3000',
            'quantity' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'numeric', 'min:0', 'max:999999999.999999', 'decimal:0,6'],
        ];
    }
}
