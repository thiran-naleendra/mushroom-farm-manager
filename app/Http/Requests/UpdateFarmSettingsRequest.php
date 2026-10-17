<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFarmSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-farm');
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'mode' => 'required|in:simple,advanced',
            'currency' => 'required|in:LKR,USD,EUR,GBP,INR',
            'address' => 'sometimes|nullable|string|max:1000',
            'phone' => 'sometimes|nullable|string|max:40',
            'email' => 'sometimes|nullable|email|max:255',
            'timezone' => ['sometimes', 'required', Rule::in(\DateTimeZone::listIdentifiers())],
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048|dimensions:max_width=2000,max_height=2000',
            'remove_logo' => 'sometimes|boolean',
            'batch_tracking' => 'sometimes|boolean',
            'qr_printing' => 'sometimes|boolean',
            'grocery_sales' => 'sometimes|boolean',
            'fresh_stock_alert_kg' => 'sometimes|nullable|numeric|min:0|max:1000000|decimal:0,3',
            'enabled_roles' => 'sometimes|array|max:3',
            'enabled_roles.*' => 'required|in:owner,manager,worker|distinct',
            'mushroom_varieties' => 'sometimes|nullable|string|max:5000',
        ];
    }
}
