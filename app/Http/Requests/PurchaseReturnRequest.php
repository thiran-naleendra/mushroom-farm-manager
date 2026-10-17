<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('receive', $this->route('purchase'));
    }

    public function rules(): array
    {
        return ['purchase_line_id' => 'required|integer|exists:purchase_lines,id', 'quantity' => 'required|numeric|min:0.000001|max:999999999.999999|decimal:0,6', 'returned_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'reason' => 'required|string|max:500', 'request_id' => 'required|uuid'];
    }
}
