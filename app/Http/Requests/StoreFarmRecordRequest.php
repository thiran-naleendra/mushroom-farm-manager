<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFarmRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('access-section', $this->route('type'));
    }

    public function rules(): array
    {
        return match ($this->route('type')) {
            'sales' => ['request_id' => 'nullable|uuid', 'customer' => 'nullable|required_without:customer_id|string|max:120', 'customer_id' => 'nullable|integer|exists:customers,id',
                'amount_paid' => 'nullable|required_with:customer_id|numeric|min:0|max:99999999999999.99|decimal:0,2',
                'sold_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'weight' => 'required|numeric|min:0.001|max:1000000|decimal:0,3', 'price_per_kg' => 'required|numeric|min:0.01|max:10000000|decimal:0,2'],
            'expenses' => ['description' => 'required|string|max:255', 'spent_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'amount' => 'required|numeric|min:0.01|max:100000000|decimal:0,2'],
            default => [],
        };
    }
}
