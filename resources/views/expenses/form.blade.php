@extends('layouts.app')
@section('content')
<h1 class="mb-6 text-3xl font-semibold">{{ $expense->exists ? 'Edit expense' : 'Add expense' }}</h1>
<x-card><x-form :action="$expense->exists ? route('expenses.update', $expense) : route('expenses.store')" :method="$expense->exists ? 'PUT' : 'POST'" class="grid gap-4 sm:grid-cols-2">
<input type="hidden" name="revision" value="{{ $expense->revision }}">
@unless($expense->exists)<input type="hidden" name="request_id" value="{{ old('request_id', (string) Str::uuid()) }}">@endunless
<x-field name="description" label="Description" :value="$expense->description" required/>
<x-field name="spent_on" label="Paid date" type="date" :value="$expense->spent_on" required/>
<x-field name="amount" :label="'Amount ('.($expense->currency ?? $farm->currency).')'" type="number" step="0.01" min="0.01" :value="$expense->amount" required/>
<label>Category<select name="expense_category_id"><option value="">Uncategorized</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('expense_category_id', $expense->expense_category_id) == $category->id)>{{ $category->name }}</option>@endforeach</select></label>
<label>Production (optional)<select name="batch_id"><option value="">None</option>@foreach($batches as $batch)<option value="{{ $batch->id }}" @selected(old('batch_id', $expense->batch_id) == $batch->id)>Production #{{ $batch->id }} · {{ $batch->variety }} · {{ $batch->started_on }}</option>@endforeach</select></label>
<label>Accounting treatment<select name="treatment"><option value="operating" @selected(old('treatment', $expense->treatment) === 'operating')>Operating expense</option><option value="production_payment" @selected(old('treatment', $expense->treatment) === 'production_payment')>Payment of cost already included in production</option></select></label>
<p class="muted sm:col-span-2">Record paid expenses here. Production payments settle existing labour/additional cost snapshots and are excluded from operating expenses. Linking an operating expense provides context only. Record material purchases in Purchases.</p>
<label class="sm:col-span-2">Notes<textarea name="notes">{{ old('notes', $expense->notes) }}</textarea></label><x-button>Save expense</x-button></x-form></x-card>
@if($expense->exists)<x-card title="History" class="mt-6">@forelse($audits as $audit)<details class="border-b py-3"><summary>{{ $audit->created_at }} · {{ $audit->action }} · User #{{ $audit->user_id }}</summary><pre class="overflow-auto text-xs">{{ json_encode(['before' => json_decode($audit->before ?? 'null'), 'after' => json_decode($audit->after ?? 'null')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details>@empty<p>No audit history for this legacy expense.</p>@endforelse{{ $audits->links() }}<x-form :action="route('expenses.destroy', $expense)" method="DELETE" class="mt-5"><x-button variant="danger">Delete expense</x-button></x-form></x-card>@endif
@endsection
