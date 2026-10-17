@extends('layouts.app')
@section('content')
<a class="text-sm text-emerald-800" href="{{ route('inventory.index') }}">&larr; Inventory</a><h1 class="mb-6 mt-4 text-3xl font-semibold">{{ $material->exists ? 'Edit material' : 'Add material' }}</h1>
@if($categories->isEmpty() || $units->isEmpty())<div class="mb-6 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Create an active category and unit under <a class="underline" href="{{ route('inventory.options') }}">Categories &amp; units</a> before adding materials.</div>@endif
<x-form :action="$material->exists ? route('inventory.update', $material) : route('inventory.store')" :method="$material->exists ? 'PUT' : 'POST'" class="max-w-3xl">
    <x-card><div class="grid gap-5 sm:grid-cols-2">
        <x-field name="name" label="Material name" :value="$material->name" required maxlength="120" />
        <x-field name="sku" label="SKU" :value="$material->sku" required maxlength="60" />
        <label>Category<select name="material_category_id" required><option value="">Choose category</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('material_category_id', $material->material_category_id) == $category->id)>{{ $category->name }}{{ $category->is_active ? '' : ' (inactive)' }}</option>@endforeach</select></label>
        <label>Supply type<select name="supply_type" required><option value="growing" @selected(old('supply_type', $material->supply_type) === 'growing')>Growing supplies</option><option value="retail" @selected(old('supply_type', $material->supply_type) === 'retail')>Retail packaging supplies</option></select></label>
        <label>Stock unit<select name="material_unit_id" required><option value="">Choose unit</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected(old('material_unit_id', $material->material_unit_id) == $unit->id)>{{ $unit->name }} ({{ $unit->symbol }}){{ $unit->is_active ? '' : ' (inactive)' }}</option>@endforeach</select></label>
        @if(!$material->exists)<x-field name="quantity" label="Opening quantity (stock unit)" type="number" value="0" min="0" max="999999999.999999" step="0.000001" required />@else<div class="rounded-xl bg-stone-50 p-4"><p class="muted">Current quantity</p><p class="mt-2 font-semibold">{{ $material->quantity }} {{ $material->unit->symbol }}</p><p class="muted mt-2 text-xs">Use a stock adjustment to change quantity. Unit changes are blocked after movements exist.</p></div>@endif
        <x-field name="unit_cost" :label="'Cost per stock unit ('.$farm->currency.')'" type="number" :value="$material->unit_cost ?? '0'" min="0" max="999999999.99" step="0.01" required />
        <x-field name="minimum_stock" label="Minimum stock (stock unit)" type="number" :value="$material->minimum_stock ?? '0'" min="0" max="999999999.999999" step="0.000001" required />
        <label>Status<select name="is_active"><option value="1" @selected(old('is_active', $material->is_active ?? true))>Active</option><option value="0" @selected(!old('is_active', $material->is_active ?? true))>Inactive</option></select></label>
        <label class="sm:col-span-2">Notes<textarea name="notes" rows="3" maxlength="3000">{{ old('notes', $material->notes) }}</textarea></label>
    </div><p class="muted mt-5">Cost is a reference cost per stock unit. Opening stock and adjustments do not create expenses.</p><div class="mt-6 flex gap-3"><x-button>Save material</x-button><x-button variant="secondary" :href="route('inventory.index')">Cancel</x-button></div></x-card>
</x-form>
@endsection
