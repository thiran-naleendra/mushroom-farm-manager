<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('void', $this->route('batch'));
    }

    public function rules(): array
    {
        return ['reason' => 'required|string|max:500'];
    }
}
