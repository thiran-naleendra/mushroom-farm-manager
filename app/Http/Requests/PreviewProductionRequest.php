<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviewProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('access-section', 'production');
    }

    public function rules(): array
    {
        return [
            'recipe_id' => 'required|integer|exists:recipes,id', 'started_on' => 'required|date_format:Y-m-d|before_or_equal:today',
            'bags' => 'required|integer|min:1|max:1000000',
            'labour_cost' => 'required|numeric|min:0|max:999999999.99|decimal:0,2', 'additional_cost' => 'required|numeric|min:0|max:999999999.99|decimal:0,2',
            'reference' => 'nullable|string|max:80', 'location' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:2000',
            'expected_harvest_on' => 'nullable|date_format:Y-m-d|after_or_equal:started_on', 'request_id' => 'required|uuid',
        ];
    }
}
