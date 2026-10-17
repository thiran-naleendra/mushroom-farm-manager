@extends('layouts.app')
@section('content')
<a class="text-sm text-emerald-800" href="{{ route('purchases.index') }}">&larr; Purchases</a>
<header class="my-6 flex flex-wrap justify-between gap-4"><div><h1 class="text-3xl font-semibold">{{ $purchase->reference }}</h1><p class="muted mt-2">{{ $purchase->supplier_name }} · {{ $purchase->purchased_on }} · {{ ucfirst($purchase->status) }}</p>@if($purchase->received_at)<p class="muted mt-2">Received {{ $purchase->received_at->format('j M Y H:i') }}</p>@endif</div>@can('view-financials')<x-button variant="secondary" :href="route('suppliers.show', $purchase->supplier_id)">Supplier report</x-button>@endcan</header>
<div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">@foreach(['Original total' => $purchase->total, 'Return credits' => $amounts['returns'], 'Payments less refunds' => (string) \App\Services\PurchaseAmounts::money($amounts['paid'])->minus($amounts['refunds']), 'Balance' => $amounts['balance']] as $label => $value)<x-card :title="$label"><p class="text-2xl font-semibold">{{ $farm->currency }} {{ number_format($value, 2) }}</p></x-card>@endforeach</div><p class="muted mb-6">Payment status: {{ ucfirst(str_replace('_', ' ', $amounts['payment_status'])) }}. A negative balance is supplier credit due back to the farm.</p>
<x-card title="Original purchase lines" description="Original quantities, prices and discounts are preserved.">
    <x-table label="Purchased materials"><x-slot:head><tr><th scope="col">Material</th><th scope="col">Quantity</th><th scope="col">Unit price</th><th scope="col">Discount</th><th scope="col">Total</th></tr></x-slot:head>
        @foreach($purchase->lines as $line)<tr><td>{{ $line->material_name }}</td><td>{{ $line->quantity }} {{ $line->unit->symbol }}</td><td>{{ $farm->currency }} {{ $line->unit_price }}</td><td>{{ $line->discount }}</td><td>{{ $line->total }}</td></tr>@endforeach
    </x-table><p class="muted mt-4">Subtotal {{ $farm->currency }} {{ $purchase->subtotal }} · Discount {{ $purchase->discount }} · Total {{ $purchase->total }}</p>
    @if($purchase->status === 'pending') @can('receive', $purchase)<x-form :action="route('purchases.receive', $purchase)" class="mt-5" x-data @submit="if (!confirm('Receive all materials into stock? This cannot be received twice.')) $event.preventDefault()"><x-button>Receive materials into stock</x-button></x-form>@endcan @endif
</x-card>
<div class="mt-6 grid items-start gap-6 xl:grid-cols-2">
    <x-card title="Payments & refunds">
        @can('pay', $purchase)<x-form :action="route('purchases.payments', $purchase)" class="mb-6 grid gap-4 sm:grid-cols-2"><input type="hidden" name="request_id" value="{{ old('request_id', (string) Str::uuid()) }}"><label>Transaction<select name="type"><option value="payment">Pay supplier</option><option value="refund">Supplier refund received</option></select></label><x-field name="amount" :label="'Amount ('.$farm->currency.')'" type="number" min="0.01" max="999999999999.99" step="0.01" required /><x-field name="paid_on" label="Payment / refund date" type="date" :value="now()->toDateString()" :min="$purchase->purchased_on" :max="now()->toDateString()" required /><x-field name="reference" label="Payment reference" required maxlength="120" /><x-button>Record transaction</x-button></x-form>@endcan
        @forelse($purchase->payments as $payment)<div class="border-t border-stone-100 py-3 text-sm"><p class="font-medium">{{ ucfirst($payment->type) }} · {{ $farm->currency }} {{ $payment->amount }}</p><p class="muted mt-1">{{ $payment->paid_on }} · {{ $payment->reference }}</p></div>@empty<p class="muted">No payment transactions recorded.</p>@endforelse
    </x-card>
    <x-card title="Purchase returns" description="Supplier credit uses the original discounted price. Inventory leaves at its current weighted-average carrying cost; any price variance is retained on the return.">
        @foreach($purchase->lines as $line)
            @php
                $returnedQuantity = $line->returns->reduce(fn ($sum, $return) => $sum->plus($return->quantity), \Brick\Math\BigDecimal::zero());
                $remaining = \Brick\Math\BigDecimal::of($line->quantity)->minus($returnedQuantity);
            @endphp
            <div class="border-t border-stone-100 py-4"><h3 class="text-sm font-semibold">{{ $line->material_name }}</h3><p class="muted mt-1">{{ $returnedQuantity }} {{ $line->unit->symbol }} returned · {{ $remaining }} available to return</p>
                @if($purchase->status === 'received' && $remaining->isPositive()) @can('receive', $purchase)<details class="mt-3"><summary class="cursor-pointer text-sm text-emerald-800">Record return</summary><x-form :action="route('purchases.returns', $purchase)" class="mt-4 space-y-4"><input type="hidden" name="purchase_line_id" value="{{ $line->id }}"><input type="hidden" name="request_id" value="{{ old('purchase_line_id') == $line->id ? old('request_id', (string) Str::uuid()) : (string) Str::uuid() }}"><x-field name="quantity" :label="'Return quantity ('.$line->unit->symbol.')'" type="number" min="0.000001" :max="(string) $remaining" step="0.000001" required /><x-field name="returned_on" label="Return date" type="date" :value="now()->toDateString()" :min="$purchase->received_at->toDateString()" :max="now()->toDateString()" required /><x-field name="reason" label="Reason" required maxlength="500" /><x-button>Return materials</x-button></x-form></details>@endcan @endif
                @foreach($line->returns as $return)<div class="mt-3 rounded-xl bg-stone-50 p-3 text-sm"><p>{{ $return->returned_on }} · {{ $return->quantity }} {{ $line->unit->symbol }} · Credit {{ $farm->currency }} {{ $return->credit }}</p><p class="muted mt-1">{{ $return->reason }}</p><p class="muted mt-1 text-xs">Inventory value removed: {{ $return->carrying_value }} · Price variance: {{ $return->price_variance }}</p></div>@endforeach
            </div>
        @endforeach
    </x-card>
</div>
@endsection
