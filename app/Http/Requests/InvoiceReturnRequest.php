<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('returnItems', $this->route('invoice'));
    }

    public function rules(): array
    {
        return ['request_id' => 'required|uuid', 'returned_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'reason' => 'required|string|max:1000',
            'lines' => 'required|array|min:1|max:100', 'lines.*.invoice_line_id' => 'required|integer|distinct|exists:invoice_lines,id',
            'lines.*.quantity' => 'required|numeric|min:0.001|max:1000000|decimal:0,3', 'lines.*.restock' => 'required|boolean'];
    }
}
