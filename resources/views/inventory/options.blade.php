@extends('layouts.app')
@section('content')
<a class="text-sm text-emerald-800" href="{{ route('inventory.index') }}">&larr; Inventory</a><h1 class="mb-6 mt-4 text-3xl font-semibold">Categories &amp; units</h1>
<div class="grid items-start gap-6 xl:grid-cols-2">
    <x-card title="Categories" description="Create categories that fit your farm. Deactivate unused categories without removing historical references.">
        @foreach($categories as $category)<x-form :action="route('inventory.options.update', ['kind' => 'categories', 'option' => $category->id])" method="PUT" class="mb-5 grid items-end gap-3 border-b border-stone-100 pb-5 sm:grid-cols-3"><x-field name="name" label="Category name" :value="$category->name" required maxlength="100" /><label>Status<select name="is_active"><option value="1" @selected($category->is_active)>Active</option><option value="0" @selected(!$category->is_active)>Inactive</option></select></label><x-button>Save category</x-button></x-form>@endforeach
        <x-form :action="route('inventory.options.store', 'categories')" class="space-y-4"><h3 class="font-medium">Add category</h3><x-field name="name" label="Category name" required maxlength="100" /><input type="hidden" name="is_active" value="1"><x-button>Add category</x-button></x-form>
    </x-card>
    <x-card title="Units" description="Factors convert to kg for mass, L for volume, and pieces for counts. A custom unit only converts to itself (factor 1). Existing unit definitions are locked once used.">
        @foreach($units as $unit)<details class="mb-4 rounded-xl border border-stone-200 p-4"><summary class="cursor-pointer text-sm font-semibold">{{ $unit->name }} ({{ $unit->symbol }}) · {{ $unit->is_active ? 'Active' : 'Inactive' }}</summary><x-form :action="route('inventory.options.update', ['kind' => 'units', 'option' => $unit->id])" method="PUT" class="mt-5 grid gap-4 sm:grid-cols-2">@include('inventory.unit-fields', ['unit' => $unit])<x-button>Save unit</x-button></x-form></details>@endforeach
        <h3 class="mb-4 mt-6 font-medium">Add unit</h3><x-form :action="route('inventory.options.store', 'units')" class="grid gap-4 sm:grid-cols-2">@include('inventory.unit-fields', ['unit' => null])<x-button>Add unit</x-button></x-form>
    </x-card>
</div>
@endsection
