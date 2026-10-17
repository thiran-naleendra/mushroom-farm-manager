<?php

namespace App\Http\Requests;

use App\Models\Recipe;
use Illuminate\Foundation\Http\FormRequest;

class SaveRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('recipe') ? $this->user()->can('update', $this->route('recipe')) : $this->user()->can('create', Recipe::class);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120', 'variety' => 'required|string|max:100', 'reference_bags' => 'required|integer|min:1|max:1000000', 'notes' => 'nullable|string|max:3000',
            'ingredients' => 'required|array|min:1|max:30', 'ingredients.*.material_id' => 'required|integer|distinct|exists:materials,id',
            'ingredients.*.material_unit_id' => 'required|integer|exists:material_units,id',
            'ingredients.*.quantity' => 'required|numeric|min:0.000001|max:999999999.999999|decimal:0,6',
        ];
    }
}
