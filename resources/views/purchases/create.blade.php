@extends('layouts.app')
@section('content')
<a class="text-sm text-emerald-800" href="{{ route('purchases.index') }}">&larr; Purchases</a><h1 class="mb-6 mt-4 text-3xl font-semibold">New material purchase</h1>
@if($suppliers->isEmpty() || $materials->isEmpty())<p class="mb-6 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Add a <a class="underline" href="{{ route('suppliers.create') }}">supplier</a> and active <a class="underline" href="{{ route('inventory.create') }}">materials</a> before creating a purchase.</p>@endif
@php
    $materialOptions = $materials->map(fn ($material) => ['id' => $material->id, 'name' => $material->name.' ('.$material->sku.')', 'unit_id' => $material->material_unit_id, 'dimension' => $material->unit->dimension])->values();
    $unitOptions = $units->map(fn ($unit) => ['id' => $unit->id, 'name' => $unit->symbol, 'dimension' => $unit->dimension])->values();
@endphp
<x-form :action="route('purchases.store')" class="space-y-6" :x-data="'purchaseForm('.Js::from(old('lines', [['material_id' => '', 'material_unit_id' => '', 'quantity' => '1', 'unit_price' => '0', 'discount' => '0']])).', '.Js::from($materialOptions).', '.Js::from($unitOptions).')'">
    <input type="hidden" name="request_id" value="{{ old('request_id', (string) Str::uuid()) }}">
    <x-card title="Purchase details"><div class="grid gap-5 sm:grid-cols-3"><label>Supplier<select name="supplier_id" required><option value="">Choose supplier</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>{{ $supplier->name }}</option>@endforeach</select></label><x-field name="purchased_on" label="Purchase date" type="date" :value="now()->toDateString()" :max="now()->toDateString()" required /><x-field name="reference" label="Supplier invoice / reference" required maxlength="120" /></div></x-card>
    <x-card title="Purchase materials" description="Prices apply to the selected input unit. Discounts are fixed currency amounts per line.">
        <div class="space-y-5"><template x-for="(line, index) in lines" :key="line.key"><div class="rounded-xl border border-stone-200 p-4"><div class="mb-4 flex items-center justify-between"><h3 class="text-sm font-semibold" x-text="'Material ' + (index + 1)"></h3><button type="button" @click="lines.splice(index, 1)" :disabled="lines.length === 1" class="text-sm text-red-700 disabled:opacity-40">Remove line</button></div><div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <label class="xl:col-span-2">Material<select :name="`lines[${index}][material_id]`" x-model="line.material_id" @change="line.material_unit_id = String(materials.find(m => m.id == line.material_id)?.unit_id ?? '')" required><option value="">Choose material</option><template x-for="material in materials" :key="material.id"><option :value="material.id" x-text="material.name"></option></template></select></label>
            <label>Quantity<input type="number" :name="`lines[${index}][quantity]`" x-model="line.quantity" min="0.000001" max="999999999.999999" step="0.000001" required></label>
            <label>Input unit<select :name="`lines[${index}][material_unit_id]`" x-model="line.material_unit_id" required><option value="">Choose unit</option><template x-for="unit in compatibleUnits(line)" :key="unit.id"><option :value="unit.id" x-text="unit.name"></option></template></select></label>
            <label>Unit price ({{ $farm->currency }})<input type="number" :name="`lines[${index}][unit_price]`" x-model="line.unit_price" min="0" max="999999999.99" step="0.01" required></label>
            <label>Line discount ({{ $farm->currency }})<input type="number" :name="`lines[${index}][discount]`" x-model="line.discount" min="0" max="999999999999.99" step="0.01" required></label>
            <p class="self-end py-3 text-sm font-medium">Line total: {{ $farm->currency }} <span x-text="lineTotal(line).toFixed(2)"></span></p>
        </div></div></template></div><x-button type="button" variant="secondary" class="mt-5" @click="addLine()" x-bind:disabled="lines.length >= 30">Add another material</x-button>
    </x-card>
    <div class="card flex flex-wrap items-center justify-between gap-4"><div><p class="text-lg font-semibold">Estimated total: {{ $farm->currency }} <span x-text="lines.reduce((sum, line) => sum + lineTotal(line), 0).toFixed(2)"></span></p><p class="muted mt-2">Totals are validated on save. Stock changes only when received. Purchases do not create production expenses.</p></div><x-button :disabled="$suppliers->isEmpty() || $materials->isEmpty()">Save pending purchase</x-button></div>
</x-form>
@endsection
