<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorrectProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('correct', $this->route('batch'));
    }

    public function rules(): array
    {
        return ['sold_bags' => 'required|integer|min:0|max:1000000', 'damaged_bags' => 'required|integer|min:0|max:1000000', 'location' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:2000', 'reason' => 'required|string|max:500'];
    }
}
