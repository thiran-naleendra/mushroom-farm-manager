@extends('layouts.app')
@section('content')
<a class="text-sm text-emerald-800" href="{{ route('inventory.index') }}">&larr; Inventory</a>
<header class="my-6 flex flex-wrap justify-between gap-4"><div><h1 class="text-3xl font-semibold">{{ $material->name }}</h1><p class="muted mt-2">{{ $material->sku }} · {{ $material->category->name }} · {{ $material->supply_type === 'growing' ? 'Growing supplies' : 'Retail packaging' }}</p></div>@if(!$material->trashed())<x-button :href="route('inventory.edit', $material)" variant="secondary">Edit material</x-button>@endif</header>
<div class="mb-6 grid gap-4 sm:grid-cols-3"><x-card title="Quantity"><p class="text-2xl font-semibold">{{ $material->quantity }} <span class="text-sm">{{ $material->unit->symbol }}</span></p></x-card><x-card title="Minimum stock"><p class="text-xl">{{ $material->minimum_stock }} {{ $material->unit->symbol }}</p>@if($material->is_active && !$material->trashed() && $material->quantity <= $material->minimum_stock)<p class="mt-3 text-sm text-amber-700">Low stock</p>@endif</x-card><x-card title="Reference cost"><p class="text-xl">{{ $farm->currency }} {{ number_format($material->unit_cost, 2) }}</p><p class="muted mt-2">Per {{ $material->unit->symbol }} · {{ $material->trashed() ? 'Archived' : ($material->is_active ? 'Active' : 'Inactive') }}</p></x-card></div>
<div class="mb-6 grid gap-4 sm:grid-cols-2"><x-card title="Weighted-average stock cost"><p class="text-xl">{{ $farm->currency }} {{ $material->average_cost }}</p><p class="muted mt-2">Per {{ $material->unit->symbol }} in current inventory</p></x-card><x-card title="Inventory carrying value"><p class="text-xl">{{ $farm->currency }} {{ $material->inventory_value }}</p><p class="muted mt-2">Purchases are held as inventory, not operating expenses.</p></x-card></div>
@if($material->notes)<p class="card mb-6 whitespace-pre-line text-sm">{{ $material->notes }}</p>@endif
@if(!$material->trashed() && $material->is_active)
<x-card title="Adjust stock" description="Add or remove stock with a reason. Correct errors with a new adjustment; history cannot be edited." class="mb-6">
    <x-form :action="route('inventory.adjust', $material)" class="grid items-end gap-4 sm:grid-cols-2 xl:grid-cols-4"><input type="hidden" name="request_id" value="{{ old('request_id', (string) Str::uuid()) }}">
        <label>Adjustment<select name="type"><option value="add" @selected(old('type') === 'add')>Add stock</option><option value="remove" @selected(old('type') === 'remove')>Remove stock</option></select></label>
        <x-field name="quantity" label="Quantity" type="number" min="0.000001" max="999999999.999999" step="0.000001" required />
        <label>Input unit<select name="material_unit_id" required>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected(old('material_unit_id', $material->material_unit_id) == $unit->id)>{{ $unit->name }} ({{ $unit->symbol }})</option>@endforeach</select></label>
        <x-field name="reason" label="Reason" required maxlength="500" />
        <div class="sm:col-span-2 xl:col-span-4"><x-button>Record adjustment</x-button></div>
    </x-form>
</x-card>
@endif
<x-card title="Stock movement history" description="Signed quantities and running balances are in the material's stock unit.">
    <x-table label="Stock movements"><x-slot:head><tr><th scope="col">Recorded</th><th scope="col">Type / reason</th><th scope="col">Input quantity</th><th scope="col">Change ({{ $material->unit->symbol }})</th><th scope="col">Balance ({{ $material->unit->symbol }})</th><th scope="col">Cost / stock unit</th><th scope="col">Recorded by</th></tr></x-slot:head>
        @forelse($movements as $movement)<tr><td class="whitespace-nowrap">{{ $movement->created_at->format('j M Y H:i') }}</td><td>{{ ucfirst($movement->type) }}<p class="muted mt-1 max-w-xs break-words">{{ $movement->reason }}</p></td><td class="whitespace-nowrap">{{ $movement->input_quantity }} {{ $movement->unit->symbol }}</td><td class="whitespace-nowrap">{{ $movement->quantity }}</td><td>{{ $movement->balance_after }}</td><td class="whitespace-nowrap">{{ $farm->currency }} {{ $movement->unit_cost }}</td><td>{{ $movement->user->name }}</td></tr>@empty<tr><td colspan="7" class="py-8 text-center text-stone-500">No stock movements recorded.</td></tr>@endforelse
    </x-table><div class="mt-5">{{ $movements->links() }}</div>
</x-card>
@if(!$material->trashed()) @can('delete', $material)
<x-form :action="route('inventory.destroy', $material)" method="DELETE" class="mt-6" x-data @submit="if (!confirm('Archive this material? Its movement history will be preserved. Stock must be zero.')) $event.preventDefault()"><x-button variant="secondary">Archive material</x-button></x-form>
@endcan @endif
@endsection
