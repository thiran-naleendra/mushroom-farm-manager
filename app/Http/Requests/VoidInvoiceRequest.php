<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('void', $this->route('invoice'));
    }

    public function rules(): array
    {
        return ['reason' => 'required|string|max:1000'];
    }
}
