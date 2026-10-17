@extends('layouts.app')
@section('content')
<header class="mb-6 flex flex-wrap justify-between gap-4"><div><h1 class="text-3xl font-semibold">Sales & invoices</h1><p class="muted mt-2">Stock-backed sales, payments and return credits.</p></div><x-button :href="route('invoices.create')">New invoice</x-button></header>
<x-card><x-form :action="route('invoices.index')" method="GET" class="mb-5 grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
<x-field name="search" label="Invoice or customer name" :value="request('search')" /><label>Customer<select name="customer_id"><option value="">All customers</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected(request('customer_id') == $customer->id)>{{ $customer->name }}</option>@endforeach</select></label>
<label>Channel<select name="channel"><option value="">All channels</option>@foreach(['direct'=>'Direct','grocery'=>'Grocery'] as $key=>$label)<option value="{{ $key }}" @selected(request('channel') === $key)>{{ $label }}</option>@endforeach</select></label>
<label>Payment status<select name="payment_status"><option value="">All payments</option>@foreach(['paid'=>'Paid','partial'=>'Partial','unpaid'=>'Unpaid','credit_due'=>'Refund due'] as $key=>$label)<option value="{{ $key }}" @selected(request('payment_status') === $key)>{{ $label }}</option>@endforeach</select></label>
<label>Invoice status<select name="status"><option value="">All statuses</option><option value="confirmed" @selected(request('status') === 'confirmed')>Confirmed</option><option value="voided" @selected(request('status') === 'voided')>Voided</option></select></label>
<x-field name="from" label="From date" type="date" :value="request('from')" /><x-field name="to" label="To date" type="date" :value="request('to')" /><x-button variant="secondary">Filter</x-button></x-form>
<x-table label="Invoices"><x-slot:head><tr><th>Invoice / date</th><th>Customer</th><th>Net sales</th><th>Balance</th><th>Status</th><th></th></tr></x-slot:head>
@forelse($invoices as $invoice)
@php($amounts = \App\Services\PurchaseAmounts::fromTotals($invoice->total, $invoice->credits_sum, $invoice->paid_sum, $invoice->refunds_sum))
<tr><td>{{ $invoice->number }}<p class="muted text-xs">{{ $invoice->sold_on }} · {{ $invoice->channel }}</p></td><td>{{ $invoice->customer_snapshot['name'] }}</td><td>{{ $invoice->currency }} {{ $amounts['net'] }}</td><td>{{ $amounts['balance'] }}</td><td>{{ ucfirst($invoice->status) }}<p class="muted text-xs">{{ str_replace('_', ' ', $amounts['payment_status']) }}</p></td><td><x-button :href="route('invoices.show', $invoice)" variant="secondary">View</x-button></td></tr>
@empty<tr><td colspan="6" class="muted py-8 text-center">No invoices recorded.</td></tr>@endforelse
</x-table><div class="mt-5">{{ $invoices->links() }}</div></x-card>
<x-card title="Legacy fresh sales" class="mt-6"><p class="muted mb-4 text-xs">Earlier sales remain separate and already count towards fresh stock and revenue.</p><details><summary class="cursor-pointer text-sm text-emerald-800">View earlier records</summary><x-table label="Legacy fresh sales" class="mt-4"><x-slot:head><tr><th>Date</th><th>Customer</th><th>Weight (kg)</th><th>Price / kg</th></tr></x-slot:head>
@forelse($legacySales as $sale)<tr><td>{{ $sale->sold_on }}</td><td>{{ $sale->customer }}</td><td>{{ $sale->weight }}</td><td>{{ $sale->price_per_kg }}</td></tr>@empty<tr><td colspan="4" class="muted py-6 text-center">No earlier sales.</td></tr>@endforelse
</x-table>{{ $legacySales->links() }}</details><x-button :href="route('sales.legacy')" variant="secondary" class="mt-4">Legacy fresh entry</x-button></x-card>
@endsection
