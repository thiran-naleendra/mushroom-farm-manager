<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target ? $this->user()->can('update', $target) : $this->user()->can('create', User::class);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user'))],
            'password' => [$this->isMethod('POST') ? 'required' : 'nullable', 'confirmed', Password::min(10)],
            'role' => 'required|in:owner,manager,worker',
            'is_active' => 'required|boolean',
            'can_work_production' => 'required|boolean',
            'can_work_harvest' => 'required|boolean',
        ];
    }
}
