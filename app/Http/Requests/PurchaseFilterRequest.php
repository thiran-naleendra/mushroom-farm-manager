<?php

namespace App\Http\Requests;

use App\Models\Purchase;
use Illuminate\Foundation\Http\FormRequest;

class PurchaseFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Purchase::class);
    }

    public function rules(): array
    {
        return ['search' => 'nullable|string|max:120', 'supplier_id' => 'nullable|integer|exists:suppliers,id', 'status' => 'nullable|in:pending,received', 'payment_status' => 'nullable|in:unpaid,partial,paid,credit_due', 'page' => 'nullable|integer|min:1'];
    }
}
