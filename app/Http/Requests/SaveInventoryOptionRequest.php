<?php

namespace App\Http\Requests;

use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveInventoryOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Material::class);
    }

    public function rules(): array
    {
        if ($this->route('kind') === 'categories') {
            return ['name' => ['required', 'string', 'max:100', Rule::unique('material_categories')->ignore($this->route('option'))], 'is_active' => 'required|boolean'];
        }

        return [
            'name' => 'required|string|max:100',
            'symbol' => ['required', 'string', 'max:20', Rule::unique('material_units')->ignore($this->route('option'))],
            'dimension' => 'required|in:mass,volume,count,custom',
            'factor' => 'required|numeric|min:0.000001|max:1000|decimal:0,6', 'is_active' => 'required|boolean',
        ];
    }
}
