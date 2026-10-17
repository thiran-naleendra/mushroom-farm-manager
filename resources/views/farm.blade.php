@extends('layouts.app')
@section('content')
@php($titles = ['dashboard' => 'Farm overview', 'production' => 'Production', 'harvests' => 'Harvests', 'sales' => 'Sales', 'expenses' => 'Expenses', 'settings' => 'Farm settings'])
<header class="mb-8 flex flex-wrap items-center justify-between gap-4"><div><p class="mb-2 text-xs font-semibold uppercase tracking-widest text-emerald-700">{{ $farm->name }}</p><h1 class="text-3xl font-semibold tracking-tight">{{ $titles[$section] }}</h1><p class="muted mt-2">{{ $section === 'dashboard' ? 'A clear picture of your growing business.' : 'Keep your farm records up to date.' }}</p></div><span class="rounded-full border border-stone-200 bg-white px-4 py-2 text-sm">{{ now()->format('D, j M Y') }}</span></header>


    <div class="grid items-start gap-6 xl:grid-cols-3">
        <section class="card xl:col-span-1" x-data="{ open: true }"><button class="flex w-full items-center justify-between text-lg font-semibold" @click="open = !open" :aria-expanded="open">{{ $section === 'production' ? 'Start production' : 'Add '.['harvests' => 'harvest', 'sales' => 'sale', 'expenses' => 'expense'][$section] }}<span aria-hidden="true" x-text="open ? '−' : '+'"></span></button>
            <form method="POST" action="{{ route('records.store', $section) }}" class="mt-6 space-y-5" x-show="open">@csrf
                @if($section === 'production')
                    <x-field name="variety" label="Mushroom variety" required maxlength="100" list="mushroom-varieties" />
                    <datalist id="mushroom-varieties">@foreach($farm->mushroom_varieties ?? [] as $variety)<option value="{{ $variety }}"></option>@endforeach</datalist>
                    <x-field name="location" label="Growing room / location" required maxlength="100" />
                    <x-field name="bags" label="Number of bags" type="number" min="1" max="1000000" required />
                    <x-field name="started_on" label="Start date" type="date" :value="now()->toDateString()" :max="now()->toDateString()" required />
                    <x-field name="expected_harvest_on" label="Expected harvest date (optional)" type="date" />
                    <label>Notes <span class="text-stone-400">(optional)</span><textarea name="notes" rows="3" maxlength="2000">{{ old('notes') }}</textarea></label>
                @elseif($section === 'harvests')
                    <label>Production<select name="batch_id" required><option value="">Choose production</option>@foreach($batches->where('stage', '!=', 'completed') as $batch)<option value="{{ $batch->id }}" @selected(old('batch_id') == $batch->id)>{{ $batch->variety }} · {{ $batch->location }} · {{ $batch->started_on }}{{ $farm->tracksBatches() ? ' · '.$batch->code : '' }}</option>@endforeach</select></label>
                    <x-field name="harvested_on" label="Harvest date" type="date" :value="now()->toDateString()" :max="now()->toDateString()" required />
                    <x-field name="weight" label="Harvest weight (kg)" type="number" step="0.001" min="0.001" max="1000000" required />
                @elseif($section === 'sales')
                    <input type="hidden" name="request_id" value="{{ old('request_id', (string) Str::uuid()) }}">
                    <p class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">Available stock: {{ $availableFresh }} kg</p>
                    <div class="space-y-4" x-data="{ customer: @js((string) old('customer_id', '')) }"><label>Customer account (optional)<select name="customer_id" x-model="customer"><option value="">Unlinked / walk-in</option>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}</option>@endforeach</select></label><div x-show="!customer"><x-field name="customer" label="Customer name" maxlength="120" x-bind:required="!customer" x-bind:disabled="!!customer" /></div><div x-show="customer"><x-field name="amount_paid" :label="'Amount paid at sale ('.$farm->currency.')'" type="number" min="0" step="0.01" x-bind:required="!!customer" x-bind:disabled="!customer" /><p class="muted mt-2 text-xs">Enter the actual payment, including zero for credit. The remaining amount updates this customer's balance.</p></div></div>
                    <x-field name="sold_on" label="Sale date" type="date" :value="now()->toDateString()" :max="now()->toDateString()" required />
                    <x-field name="weight" label="Weight (kg)" type="number" step="0.001" min="0.001" max="1000000" required />
                    <x-field name="price_per_kg" :label="'Price per kg ('.$farm->currency.')'" type="number" step="0.01" min="0.01" max="10000000" required />
                @else
                    <x-field name="description" label="Description" required maxlength="255" />
                    <x-field name="spent_on" label="Expense date" type="date" :value="now()->toDateString()" :max="now()->toDateString()" required />
                    <x-field name="amount" :label="'Amount ('.$farm->currency.')'" type="number" step="0.01" min="0.01" max="100000000" required />
                @endif
                <button class="btn w-full">Save record</button>
            </form>
        </section>
        <section class="space-y-4 xl:col-span-2">
            @if($section === 'production')
                @forelse($batches as $batch)
                    <article class="card"><div class="flex flex-wrap justify-between gap-3"><div><h2 class="text-lg font-semibold">{{ $batch->variety }}</h2><p class="muted mt-1">{{ $batch->location }} · Started {{ $batch->started_on }}</p>@if($farm->tracksBatches())<p class="mt-2 break-all font-mono text-xs text-stone-500">{{ $batch->code }}</p>@endif</div><span class="badge self-start">{{ ucfirst($batch->stage) }}</span></div><div class="my-5 flex gap-8"><p><strong>{{ $batch->bags }}</strong> <span class="muted">bags</span></p><p><strong>{{ number_format($batch->harvests_sum_weight ?? 0, 3) }}</strong> <span class="muted">kg harvested</span></p></div>@if($batch->notes)<p class="muted mb-4 whitespace-pre-line">{{ $batch->notes }}</p>@endif
                        <form method="POST" action="{{ route('production.stage', $batch) }}" class="flex flex-wrap items-end gap-3 border-t border-stone-100 pt-4">@csrf @method('PATCH')<label class="min-w-40 flex-1">Stage<select name="stage">@foreach(['incubating', 'fruiting', 'completed'] as $stage)<option value="{{ $stage }}" @selected($batch->stage === $stage)>{{ ucfirst($stage) }}</option>@endforeach</select></label><label class="min-w-40 flex-1">Contaminated bags<input type="number" name="contaminated_bags" min="0" max="{{ $batch->bags }}" value="{{ $batch->contaminated_bags }}" required></label><label class="min-w-40 flex-1">Expected harvest<input type="date" name="expected_harvest_on" min="{{ $batch->started_on }}" value="{{ $batch->expected_harvest_on }}"></label><button class="btn">Update stage</button>@if($farm->printsQr())<a class="rounded-xl border border-stone-200 px-4 py-2.5 text-sm" href="{{ route('production.label', $batch) }}">Print QR label</a>@endif</form>
                    </article>
                @empty<div class="card py-12 text-center"><h2 class="font-semibold">No production yet</h2><p class="muted mt-2">Add your first grow using the form.</p></div>@endforelse
            @else
                <div class="card"><h2 class="mb-5 text-lg font-semibold">{{ ucfirst($section) }} history</h2>
                    @forelse($records as $record)
                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-stone-100 py-4"><div><p class="font-medium">{{ $section === 'harvests' ? $record->variety.' · '.$record->location : ($section === 'sales' ? $record->customer : $record->description) }}</p><p class="muted mt-1">{{ $record->harvested_on ?? $record->sold_on ?? $record->spent_on }}</p>@if($section === 'harvests' && $farm->tracksBatches())<p class="mt-1 break-all font-mono text-xs text-stone-500">{{ $record->code }}</p>@endif</div><div class="text-right"><p class="font-semibold">{{ $section === 'expenses' ? $farm->currency.' '.number_format($record->amount, 2) : number_format($record->weight, 3).' kg' }}</p>@if($section === 'sales')<p class="muted mt-1">{{ $farm->currency }} {{ number_format($record->weight * $record->price_per_kg, 2) }}</p>@endif</div></div>
                    @empty<div class="py-10 text-center"><p class="font-medium">No {{ $section }} recorded</p><p class="muted mt-2">Your saved records will appear here.</p></div>@endforelse
                    <div class="mt-4">{{ $records->links() }}</div>
                </div>
            @endif
        </section>
    </div>

@endsection
