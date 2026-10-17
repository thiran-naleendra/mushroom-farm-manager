<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Invoice::class);
    }

    public function rules(): array
    {
        return [
            'request_id' => 'required|uuid', 'customer_id' => 'nullable|required_if:sale_type,credit|required_if:channel,grocery|integer|exists:customers,id',
            'customer_name' => 'nullable|required_without:customer_id|string|max:120', 'channel' => 'required|in:direct,grocery', 'sale_type' => 'required|in:cash,bank,credit',
            'sold_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'notes' => 'nullable|string|max:2000',
            'amount_paid' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2', 'payment_reference' => 'nullable|string|max:255',
            'lines' => 'required|array|min:1|max:100', 'lines.*.product_id' => 'required|integer|distinct|exists:products,id',
            'lines.*.quantity' => 'required|numeric|min:0.001|max:1000000|decimal:0,3',
            'lines.*.selling_price' => 'required|numeric|min:0|max:999999999.99|decimal:0,2',
            'lines.*.discount' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2',
        ];
    }
}
