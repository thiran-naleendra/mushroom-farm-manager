<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pay', $this->route('invoice'));
    }

    public function rules(): array
    {
        return ['request_id' => 'required|uuid', 'type' => 'required|in:payment,refund', 'method' => 'required|in:cash,bank',
            'amount' => 'required|numeric|min:0.01|max:999999999999.99|decimal:0,2', 'paid_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'reference' => 'nullable|string|max:255'];
    }
}
