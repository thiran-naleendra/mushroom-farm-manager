<?php

namespace App\Http\Requests;

use App\Models\Batch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('access-section', 'production');
    }

    public function rules(): array
    {
        return [
            'stage' => ['required', Rule::in(Batch::STAGES)],
            'reason' => 'required|string|max:500',
            'contaminated_bags' => 'sometimes|required|integer|min:0|max:'.$this->route('batch')->bags,
            'discarded_bags' => 'sometimes|required|integer|min:0|max:'.$this->route('batch')->bags,
            'growth_notes' => 'sometimes|nullable|string|max:3000',
            'prepared_on' => 'sometimes|nullable|date_format:Y-m-d|before_or_equal:today|after_or_equal:'.$this->route('batch')->started_on,
            'incubating_on' => 'sometimes|nullable|date_format:Y-m-d|before_or_equal:today|after_or_equal:'.$this->route('batch')->started_on,
            'fruiting_on' => 'sometimes|nullable|date_format:Y-m-d|before_or_equal:today|after_or_equal:'.$this->route('batch')->started_on,
            'harvesting_on' => 'sometimes|nullable|date_format:Y-m-d|before_or_equal:today|after_or_equal:'.$this->route('batch')->started_on,
            'completed_on' => 'sometimes|nullable|date_format:Y-m-d|before_or_equal:today|after_or_equal:'.$this->route('batch')->started_on,
            'expected_harvest_on' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:'.$this->route('batch')->started_on,
        ];
    }
}
