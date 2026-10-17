@extends('layouts.app')
@section('content')
<header class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div><p class="text-xs font-semibold uppercase tracking-widest text-emerald-700">{{ $farm->name }}</p><h1 class="mt-2 text-3xl font-semibold tracking-tight">Farm overview</h1><p class="muted mt-2">Your growing business, from cultivation to harvest.</p></div>
    <span class="rounded-full border border-stone-200 bg-white px-4 py-2 text-sm">{{ now()->format('D, j M Y') }}</span>
</header>
<nav aria-label="Quick actions" class="mb-6 flex flex-wrap gap-2">
    @foreach(['production' => 'Start production', 'harvests' => 'Record a harvest', 'sales' => 'Record a sale', 'expenses' => 'Add expense'] as $key => $label)
        @can('access-section', $key)<x-button :href="route('farm.section', $key)" :variant="$key === 'harvests' ? 'primary' : 'secondary'">{{ $label }}</x-button>@endcan
    @endforeach
</nav>
@php
    $cards = [
        'retail_packets' => ['Available 200g packets', 'pcs', 'Ready to sell · active products, excluding expired packets', 0],
        'active_bags' => ['Active cultivation bags', 'Bags', 'Healthy bags in active grows; excludes sold, damaged, contaminated and discarded', 0],
        'produced_bags' => ['Total produced bags', 'Bags', 'All time · all production stages', 0],
        'contaminated_bags' => ['Contaminated bags', 'Bags', 'All time · recorded contamination', 0],
        'harvested_kg' => ['Harvested mushrooms (kg)', 'kg', 'All time · recorded harvests', 3],
        'fresh_kg' => ['Fresh mushroom stock', 'kg', 'Saleable harvests less fresh sales and packaging', 3],
        'packaged_stock' => ['Packaged product stock', 'packets', 'Finished packets in stock', 0],
        'revenue' => ['Monthly sales revenue', $farm->currency, $monthLabel, 2],
        'expenses' => ['Operating expenses', $farm->currency, $monthLabel.' · recorded expenses', 2],
        'profit' => ['Net profit (estimate)', $farm->currency, $monthLabel.' · recorded revenue less COGS and operating expenses', 2],
        'outstanding' => ['Customer outstanding balance', $farm->currency, 'Recorded customer accounts · current currency', 2],
    ];
@endphp
<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5">
    @foreach($cards as $key => [$label, $unit, $description, $precision])
        @if(array_key_exists($key, $metrics))
            <x-card class="min-w-0" :data-kpi="$key"><p class="muted">{{ $label }}</p><p class="mt-4 break-words text-3xl font-semibold tracking-tight {{ $key === 'profit' && $metrics[$key] < 0 ? 'text-red-700' : 'text-emerald-950' }}">{{ $metrics[$key] === null ? '—' : number_format($metrics[$key], $precision) }} @if($metrics[$key] !== null)<span class="text-xs font-medium text-stone-500">{{ $unit }}</span>@endif</p><p class="mt-3 text-xs leading-relaxed text-stone-500">{{ $description }}</p></x-card>
        @endif
    @endforeach
</div>
@if($metrics === [])<x-card title="Welcome to your farm" description="No tasks have been authorized for your account yet. Contact an Owner to enable your production or harvest access." />@endif

@if($financial)
    <x-card title="Sales chart" :description="'Monthly recorded sales · '.$farm->currency.' · last six months'" class="mt-6">
        @php($maximum = max(array_column($chart, 'total')))
        @if($maximum > 0)
            <div x-data="{ selected: 5, months: @js($chart) }">
                <div class="mb-5 flex flex-wrap items-baseline justify-between gap-2"><p class="text-sm font-medium" x-text="months[selected].label">{{ $chart[5]['label'] }}</p><p class="text-xl font-semibold text-emerald-900"><span>{{ $farm->currency }}</span> <span x-text="Number(months[selected].total).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})">{{ number_format($chart[5]['total'], 2) }}</span></p></div>
                <div class="grid grid-cols-6 gap-2 sm:gap-5" role="group" aria-label="Select a month to view its sales">
                    @foreach($chart as $point)
                        <button type="button" class="min-w-0 rounded-xl p-1 hover:bg-emerald-50" @click="selected = {{ $loop->index }}" :aria-pressed="selected === {{ $loop->index }}" aria-label="{{ $point['label'] }}: {{ $farm->currency }} {{ number_format($point['total'], 2) }}">
                            <span class="flex h-40 items-end justify-center border-b border-stone-200 sm:h-48"><span class="block w-full max-w-14 rounded-t-lg bg-emerald-600" :class="selected === {{ $loop->index }} ? 'bg-emerald-800' : 'bg-emerald-500'" style="height: {{ round($point['total'] / $maximum * 100, 2) }}%"></span></span><span class="mt-3 block text-[10px] text-stone-600 sm:text-xs">{{ $point['label'] }}</span>
                        </button>
                    @endforeach
                </div>
                <p class="sr-only" aria-live="polite" x-text="months[selected].label + ': {{ $farm->currency }} ' + Number(months[selected].total).toFixed(2)"></p>
            </div>
        @else<p class="rounded-xl bg-stone-50 p-8 text-center text-sm text-stone-500">No sales recorded in the last six months.</p>@endif
        <details class="mt-5"><summary class="cursor-pointer text-sm font-medium text-emerald-800">View sales figures</summary><x-table class="mt-4" label="Sales by month"><x-slot:head><tr><th scope="col">Month</th><th scope="col">Revenue ({{ $farm->currency }})</th></tr></x-slot:head>
@foreach($chart as $point)<tr><td>{{ $point['label'] }}</td><td>{{ number_format($point['total'], 2) }}</td></tr>@endforeach</x-table></details>
        <p class="muted mt-4 text-xs">Revenue uses recorded sales less returns. Estimated net profit deducts known cost of goods sold and operating expenses once; unknown costs are excluded. Payments are separate from profit.</p>
    </x-card>
@endif

<div class="mt-6 grid items-start gap-6 xl:grid-cols-2">
    @can('access-section', 'production')
        <x-card title="Production summary" description="All time · bags by current stage">
            @if($summary->isEmpty())<p class="muted py-6 text-center">No production recorded yet.</p>@else
                <x-table label="Production summary"><x-slot:head><tr><th scope="col">Stage</th><th scope="col">Grows</th><th scope="col">Bags</th><th scope="col">Contaminated</th></tr></x-slot:head>
                    @foreach(\App\Models\Batch::STAGES as $stage)@php($row = $summary->firstWhere('stage', $stage))<tr><td><span class="badge">{{ ucfirst($stage) }}</span></td><td>{{ number_format($row?->grows ?? 0) }}</td><td>{{ number_format($row?->bags ?? 0) }}</td><td>{{ number_format($row?->contaminated ?? 0) }}</td></tr>@endforeach
                </x-table>
                <a class="mt-4 inline-block text-sm text-emerald-800" href="{{ route('farm.section', 'production') }}">Manage production &rarr;</a>
            @endif
        </x-card>
    @endcan
    @can('access-section', 'inventory')
        <x-card title="Low-stock alerts" description="Based on your configured fresh-stock threshold">
            @if($farm->fresh_stock_alert_kg === null)<p class="muted">No fresh-stock alert threshold configured.</p>@can('manage-farm')<a class="mt-3 inline-block text-sm text-emerald-800" href="{{ route('settings.edit') }}">Configure stock alerts &rarr;</a>@endcan
            @elseif($lowStock)<div role="status" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><p class="font-semibold">Fresh mushroom stock is low</p><p class="mt-2">{{ number_format($metrics['fresh_kg'], 3) }} kg available. Alert threshold: {{ number_format($farm->fresh_stock_alert_kg, 3) }} kg.</p></div>
            @else<p class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">No low-stock alerts. Fresh stock is above {{ number_format($farm->fresh_stock_alert_kg, 3) }} kg.</p>@endif
            @forelse($materialAlerts as $material)<a class="mt-4 block rounded-xl bg-amber-50 p-3 text-sm text-amber-900" href="{{ route('inventory.show', $material) }}"><strong>{{ $material->name }}</strong>: {{ $material->quantity }} {{ $material->unit->symbol }} available · minimum {{ $material->minimum_stock }} {{ $material->unit->symbol }}</a>@empty<p class="muted mt-4 text-xs">No low-stock material alerts.</p>@endforelse
            <a class="mt-4 inline-block text-sm text-emerald-800" href="{{ route('inventory.index', ['low_stock' => 1]) }}">View material stock alerts &rarr;</a>
        </x-card>
    @endcan
    @if(auth()->user()->can('access-section', 'production') || auth()->user()->can('access-section', 'harvests'))
        <x-card title="Upcoming harvest reminders" description="Entered expected harvest dates · next 14 days">
            @forelse($reminders as $batch)
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 py-4 last:border-0"><div><h3 class="text-sm font-semibold">{{ $batch->variety }}</h3><p class="muted mt-1">{{ $batch->location }} · {{ number_format($batch->availableBags()) }} active bags</p></div><span class="badge">{{ \Carbon\Carbon::parse($batch->expected_harvest_on)->format('j M Y') }}</span></div>
            @empty<p class="muted py-6 text-center">No expected harvest dates in the next 14 days.</p>@endforelse
            @can('access-section', 'production')<p class="muted mt-4 text-xs">Set an expected harvest date in production to add a reminder. Dates are never estimated automatically.</p>@endcan
        </x-card>
    @endif
    <x-card title="Recent activities" description="Latest recorded changes you are authorized to view">
        @forelse($activities as $activity)
            <a href="{{ route('farm.section', $activity['section']) }}" class="block border-b border-stone-100 py-4 last:border-0 hover:text-emerald-800"><div class="flex flex-wrap justify-between gap-2"><p class="text-sm font-semibold">{{ $activity['label'] }}</p><time class="text-xs text-stone-400" datetime="{{ $activity['at']->toIso8601String() }}">{{ $activity['at']->timezone($farm->timezone)->format('j M, H:i') }}</time></div><p class="muted mt-1">{{ $activity['detail'] }}</p></a>
        @empty<p class="muted py-6 text-center">No recent activities recorded.</p>@endforelse
    </x-card>
</div>
@endsection
