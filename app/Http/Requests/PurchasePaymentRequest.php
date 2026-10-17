<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchasePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pay', $this->route('purchase'));
    }

    public function rules(): array
    {
        return ['type' => 'required|in:payment,refund', 'amount' => 'required|numeric|min:0.01|max:999999999999.99|decimal:0,2', 'paid_on' => 'required|date_format:Y-m-d|before_or_equal:today|after_or_equal:'.$this->route('purchase')->purchased_on, 'reference' => 'required|string|max:120', 'request_id' => 'required|uuid'];
    }
}
