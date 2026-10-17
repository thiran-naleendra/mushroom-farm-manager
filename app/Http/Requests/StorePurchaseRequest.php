<?php

namespace App\Http\Requests;

use App\Models\Purchase;
use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Purchase::class);
    }

    public function rules(): array
    {
        return [
            'supplier_id' => 'required|integer|exists:suppliers,id', 'purchased_on' => 'required|date_format:Y-m-d|before_or_equal:today',
            'reference' => 'required|string|max:120', 'request_id' => 'required|uuid',
            'lines' => 'required|array|min:1|max:30',
            'lines.*.material_id' => 'required|integer|distinct|exists:materials,id',
            'lines.*.material_unit_id' => 'required|integer|exists:material_units,id',
            'lines.*.quantity' => 'required|numeric|min:0.000001|max:999999999.999999|decimal:0,6',
            'lines.*.unit_price' => 'required|numeric|min:0|max:999999999.99|decimal:0,2',
            'lines.*.discount' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2',
        ];
    }
}
