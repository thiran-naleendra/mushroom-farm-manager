<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('material'));
    }

    public function rules(): array
    {
        return [
            'type' => 'required|in:add,remove',
            'quantity' => 'required|numeric|min:0.000001|max:999999999.999999|decimal:0,6',
            'material_unit_id' => 'required|integer|exists:material_units,id',
            'reason' => 'required|string|max:500', 'request_id' => 'required|uuid',
        ];
    }
}
