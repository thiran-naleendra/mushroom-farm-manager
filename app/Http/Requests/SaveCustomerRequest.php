<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('customer') ? $this->user()->can('update', $this->route('customer')) : $this->user()->can('create', Customer::class);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120', 'type' => ['required', Rule::in(array_keys(Customer::TYPES))],
            'phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:255', 'address' => 'nullable|string|max:2000',
            'credit_limit' => 'required|numeric|min:0|max:999999999.99|decimal:0,2', 'is_active' => 'required|boolean', 'notes' => 'nullable|string|max:2000',
            'opening_balance' => $this->route('customer') ? 'prohibited' : 'nullable|numeric|min:0|max:999999999.99|decimal:0,2',
            'opening_reason' => ['nullable', Rule::requiredIf(fn () => is_numeric($this->input('opening_balance')) && (float) $this->input('opening_balance') > 0), 'string', 'max:1000'],
        ];
    }
}
