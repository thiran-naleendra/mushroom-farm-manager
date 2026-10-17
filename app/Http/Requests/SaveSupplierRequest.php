<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;

class SaveSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('supplier') ? $this->user()->can('update', $this->route('supplier')) : $this->user()->can('create', Supplier::class);
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:120', 'phone' => 'nullable|string|max:40', 'address' => 'nullable|string|max:1000', 'email' => 'nullable|email|max:255', 'notes' => 'nullable|string|max:3000'];
    }
}
