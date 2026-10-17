<?php

namespace App\Http\Requests;

use App\Models\Farm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class SetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! Farm::exists();
    }

    public function rules(): array
    {
        return [
            'farm_name' => 'required|string|max:120', 'name' => 'required|string|max:120',
            'email' => 'required|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Password::min(10)],
            'mode' => 'required|in:simple,advanced', 'currency' => 'required|in:LKR,USD,EUR,GBP,INR',
        ];
    }
}
