@extends('layouts.app')
@section('content')
<header class="mb-6"><h1 class="text-3xl font-semibold">{{ $product->exists ? 'Edit product' : 'Add product' }}</h1></header>
@php
    $selectedType = old('type', $product->type ?? 'packaged');
    $selectedFreshUnit = old('unit', $product->unit);
    $selectedFreshUnit = in_array($selectedFreshUnit, ['kg', 'g'], true) ? $selectedFreshUnit : 'kg';
@endphp
<div x-data="{ productType: @js($selectedType), freshUnit: @js($selectedFreshUnit) }">
<x-card class="max-w-3xl"><x-form :action="$product->exists ? route('products.update', $product) : route('products.store')" :method="$product->exists ? 'PUT' : 'POST'" class="grid gap-5 sm:grid-cols-2">
<input type="hidden" name="unit" value="{{ $selectedType === 'fresh' ? $selectedFreshUnit : ($selectedType === 'packaged' ? 'g' : 'bag') }}" x-bind:value="productType === 'fresh' ? freshUnit : (productType === 'packaged' ? 'g' : 'bag')">
<x-field name="name" label="Product name" :value="$product->name" required maxlength="150" /><x-field name="sku" label="SKU" :value="$product->sku" required maxlength="80" />
<label>Product type<select name="type" x-model="productType" required>@foreach(\App\Models\Product::TYPES as $value => $label)<option value="{{ $value }}" @selected($selectedType === $value)>{{ $label }}</option>@endforeach</select></label>
<x-field name="variety" label="Mushroom variety" :value="$product->variety" list="product-varieties" required maxlength="100" /><datalist id="product-varieties">@foreach($farm->mushroom_varieties ?? [] as $variety)<option value="{{ $variety }}"></option>@endforeach</datalist>
<div x-show="productType === 'packaged'"><x-field name="pack_size_grams" label="Pack weight (grams)" type="number" :value="$product->pack_size_grams ?? \App\Models\Product::DEFAULT_PACK_SIZE_GRAMS" min="1" max="1000000" step="1" list="pack-sizes" x-bind:required="productType === 'packaged'" x-bind:disabled="productType !== 'packaged'" /><datalist id="pack-sizes">@foreach([200,100,250,500,1000] as $size)<option value="{{ $size }}">{{ $size === 1000 ? '1kg' : $size.'g' }}</option>@endforeach</datalist><p class="muted mt-2 text-xs">Default: 200g. Selling unit: Packet (pcs). Custom whole-gram sizes are optional.</p></div>
<div x-show="productType === 'fresh'"><x-field name="weight" label="Reference weight" type="number" :value="$product->type === 'fresh' ? $product->weight : 1" min="0.001" max="1000000" step="0.001" x-bind:disabled="productType !== 'fresh'" /><label class="mt-3">Unit<select x-model="freshUnit" x-bind:disabled="productType !== 'fresh'"><option value="kg" @selected($selectedFreshUnit === 'kg')>kg</option><option value="g" @selected($selectedFreshUnit === 'g')>g</option></select></label><p class="muted mt-2 text-xs">Reference weight describes the item. Fresh stock is shared by variety.</p></div>
<div x-show="productType === 'cultivation_bag'"><p class="muted">Unit: bag. Availability comes from healthy, unsold bags in active production.</p></div>
<div><x-field name="selling_price" :label="'Selling price ('.$farm->currency.', optional)'" type="number" :value="$product->selling_price" min="0" max="999999999.99" step="0.01" /><p class="muted mt-2 text-xs" x-text="productType === 'fresh' ? 'Price per selected weight unit (kg or g).' : (productType === 'packaged' ? 'Price per completed packet.' : 'Price per cultivation bag.')"></p></div>
<div class="sm:col-span-2"><label>Notes<textarea name="notes" maxlength="2000" rows="3">{{ old('notes', $product->notes) }}</textarea></label></div><x-toggle name="is_active" label="Active product" :checked="$product->is_active" /><div class="flex flex-wrap gap-2 sm:col-span-2"><x-button>Save product</x-button><x-button :href="route('products.index')" variant="secondary">Cancel</x-button></div>
</x-form>@if($product->exists)@can('delete', $product)<x-form :action="route('products.destroy', $product)" method="DELETE" class="mt-6 border-t border-stone-200 pt-5"><p class="muted mb-3 text-xs">Only empty products can be archived. History is retained.</p><x-button variant="secondary">Archive product</x-button></x-form>@endcan @endif</x-card>
</div>
@endsection
