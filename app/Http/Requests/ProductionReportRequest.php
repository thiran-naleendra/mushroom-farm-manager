<?php

namespace App\Http\Requests;

use App\Models\Batch;
use App\Models\Farm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductionReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view-financials') && Farm::firstOrFail()->isAdvanced();
    }

    public function rules(): array
    {
        return ['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'batch_id' => 'nullable|integer|exists:batches,id', 'location' => 'nullable|string|max:100',
            'stage' => ['nullable', Rule::in(Batch::STAGES)], 'page' => 'nullable|integer|min:1'];
    }
}
