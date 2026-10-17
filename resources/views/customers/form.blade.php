@extends('layouts.app')
@section('content')
<header class="mb-6"><h1 class="text-3xl font-semibold">{{ $customer->exists ? 'Edit customer' : 'Add customer' }}</h1></header>
<x-card class="max-w-3xl"><x-form :action="$customer->exists ? route('customers.update', $customer) : route('customers.store')" :method="$customer->exists ? 'PUT' : 'POST'" class="grid gap-5 sm:grid-cols-2">
<x-field name="name" label="Customer name" :value="$customer->name" required maxlength="120" /><label>Customer type<select name="type" required>@foreach(\App\Models\Customer::TYPES as $value => $label)<option value="{{ $value }}" @selected(old('type', $customer->type) === $value)>{{ $label }}</option>@endforeach</select></label>
<x-field name="phone" label="Phone" :value="$customer->phone" maxlength="30" /><x-field name="email" label="Email" type="email" :value="$customer->email" maxlength="255" />
<div class="sm:col-span-2"><label>Address<textarea name="address" rows="3" maxlength="2000">{{ old('address', $customer->address) }}</textarea></label></div>
<div><x-field name="credit_limit" :label="'Credit limit ('.($customer->currency ?? $farm->currency).')'" type="number" :value="$customer->credit_limit" min="0" max="999999999.99" step="0.01" required /><p class="muted mt-2 text-xs">Zero means full payment is required for linked sales.</p></div>
<x-toggle name="is_active" label="Active customer" :checked="$customer->is_active" />
@if(! $customer->exists && auth()->user()->isOwner())<x-field name="opening_balance" :label="'Opening outstanding ('.$farm->currency.', optional)'" type="number" min="0" max="999999999.99" step="0.01" /><x-field name="opening_reason" label="Opening balance reason" maxlength="1000" />@endif
<div class="sm:col-span-2"><label>Notes<textarea name="notes" rows="3" maxlength="2000">{{ old('notes', $customer->notes) }}</textarea></label></div>
<div class="flex flex-wrap gap-2 sm:col-span-2"><x-button>Save customer</x-button><x-button :href="route('customers.index')" variant="secondary">Cancel</x-button></div>
</x-form></x-card>
@endsection
