<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustCustomerBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('adjustBalance', $this->route('customer'));
    }

    public function rules(): array
    {
        return ['request_id' => 'required|uuid', 'direction' => 'required|in:increase,decrease', 'amount' => 'required|numeric|min:0.01|max:999999999.99|decimal:0,2', 'reason' => 'required|string|max:1000'];
    }
}
