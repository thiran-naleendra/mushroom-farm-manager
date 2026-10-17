<?php

namespace App\Http\Requests;

use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Expense::class);
    }

    public function rules(): array
    {
        return [
            'description' => 'required|string|max:255',
            'spent_on' => 'required|date_format:Y-m-d|before_or_equal:today',
            'request_id' => 'nullable|uuid',
            'amount' => 'required|numeric|min:0.01|max:100000000|decimal:0,2',
            'expense_category_id' => 'nullable|exists:expense_categories,id',
            'batch_id' => 'nullable|exists:batches,id',
            'treatment' => 'sometimes|required|in:operating,production_payment',
            'notes' => 'nullable|string|max:5000',
            'revision' => 'nullable|integer|min:1',
        ];
    }
}
