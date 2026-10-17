<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('access-section', 'production');
    }

    public function rules(): array
    {
        return ['quote' => 'required|string|max:100000'];
    }
}
