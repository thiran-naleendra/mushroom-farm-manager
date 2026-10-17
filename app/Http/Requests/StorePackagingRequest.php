<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePackagingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('access-section', 'packaging');
    }

    public function rules(): array
    {
        return [
            'request_id' => 'required|uuid', 'product_id' => 'required|integer|exists:products,id', 'harvest_id' => 'required|integer|exists:harvests,id',
            'packaged_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'expires_on' => 'nullable|date_format:Y-m-d|after_or_equal:packaged_on',
            'packet_count' => 'required|integer|min:1|max:1000000', 'wastage' => 'required|numeric|min:0|max:1000000|decimal:0,3',
            'polythene_id' => 'required|integer|exists:materials,id', 'label_id' => 'nullable|integer|different:polythene_id|exists:materials,id',
            'packaging_cost' => 'required|numeric|min:0|max:999999999.99|decimal:0,2',
            'fresh_cost_per_kg' => 'nullable|numeric|min:0|max:999999999.99|decimal:0,2', 'notes' => 'nullable|string|max:2000',
        ];
    }
}
