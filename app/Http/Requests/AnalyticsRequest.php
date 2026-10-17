<?php

namespace App\Http\Requests;

use App\Services\AnalyticsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AnalyticsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $default = collect(array_keys(AnalyticsService::REPORTS))->first(fn ($report) => Gate::allows('view-report', $report));
        $this->merge(['report' => $this->input('report', $default), 'from' => $this->input('from') ?: today()->startOfMonth()->toDateString(), 'to' => $this->input('to') ?: today()->toDateString(), 'group' => $this->input('group', 'daily')]);
    }

    public function authorize(): bool
    {
        return Gate::allows('view-report', (string) $this->input('report'));
    }

    public function rules(): array
    {
        return ['report' => ['required', Rule::in(array_keys(AnalyticsService::REPORTS))], 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'group' => 'required|in:daily,monthly,yearly', 'low_stock' => 'sometimes|boolean', 'format' => 'nullable|in:csv,print', 'page' => 'nullable|integer|min:1'];
    }
}
