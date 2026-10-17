<?php

namespace App\Http\Requests;

use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;

class InventoryFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Material::class);
    }

    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:120', 'category' => 'nullable|integer|exists:material_categories,id',
            'supply_type' => 'nullable|in:growing,retail', 'status' => 'nullable|in:active,inactive,archived',
            'low_stock' => 'nullable|in:1', 'page' => 'nullable|integer|min:1',
        ];
    }
}
