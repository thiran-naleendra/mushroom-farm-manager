<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreHarvestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('access-section', 'harvests');
    }

    public function rules(): array
    {
        return [
            'batch_id' => 'nullable|integer|exists:batches,id', 'variety' => 'required_without:batch_id|nullable|string|max:100',
            'harvested_on' => 'required|date_format:Y-m-d|before_or_equal:today',
            'weight' => 'required|numeric|min:0.001|max:1000000|decimal:0,3',
            'rejected_weight' => 'nullable|numeric|min:0|max:1000000|decimal:0,3|lte:weight',
            'cycle' => 'nullable|integer|min:1|max:1000000', 'notes' => 'nullable|string|max:2000', 'request_id' => 'required|uuid',
        ];
    }
}
