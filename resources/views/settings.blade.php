@extends('layouts.app')
@section('content')
<header class="mb-8"><p class="text-xs font-semibold uppercase tracking-widest text-emerald-700">Your farm</p><h1 class="mt-2 text-3xl font-semibold tracking-tight">Farm settings</h1><p class="muted mt-2">Make Farm Manager work for your farm. Changes apply across this installation.</p></header>
<x-form :action="route('settings.update')" method="PUT" :uploads="true" class="space-y-6" :x-data="Js::from(['mode' => old('mode', $farm->mode), 'tracking' => (bool) old('batch_tracking', $farm->batch_tracking)])">
    <div class="grid items-start gap-6 xl:grid-cols-2">
        <x-card title="Farm profile" description="Your farm identity and contact details.">
            <div class="space-y-5">
                <x-field name="name" label="Farm name" :value="$farm->name" required maxlength="120" autocomplete="organization" />
                <div class="flex flex-wrap items-start gap-4">
                    @if($farm->logo_path)<img src="{{ route('farm.logo') }}" alt="Current farm logo" class="size-20 rounded-xl border border-stone-200 bg-white object-contain p-2">@endif
                    <div class="min-w-0 flex-1"><label>Farm logo<input name="logo" type="file" accept="image/jpeg,image/png,image/webp" class="mt-2" aria-describedby="logo-help"></label><p id="logo-help" class="muted mt-2 text-xs">JPEG, PNG or WebP. Up to 2 MB and 2000 × 2000 pixels.</p>@error('logo')<p class="mt-2 text-xs text-red-700">{{ $message }}</p>@enderror</div>
                </div>
                @if($farm->logo_path)<x-toggle name="remove_logo" label="Remove current logo" />@endif
                <label>Address<textarea name="address" rows="3" maxlength="1000" autocomplete="street-address">{{ old('address', $farm->address) }}</textarea></label>
                <div class="grid gap-5 sm:grid-cols-2"><x-field name="phone" label="Phone" type="tel" :value="$farm->phone" maxlength="40" autocomplete="tel" /><x-field name="email" label="Farm email" type="email" :value="$farm->email" maxlength="255" autocomplete="email" /></div>
            </div>
        </x-card>
        <div class="space-y-6">
            <x-card title="Regional preferences">
                <div class="space-y-5"><label>Currency<select name="currency" required>@foreach(['LKR', 'USD', 'EUR', 'GBP', 'INR'] as $currency)<option @selected(old('currency', $farm->currency ?? 'LKR') === $currency)>{{ $currency }}</option>@endforeach</select></label><p class="muted">Changing currency updates labels; existing amounts are not converted.</p><label>Timezone<select name="timezone" required>@foreach($timezones as $timezone)<option @selected(old('timezone', $farm->timezone) === $timezone)>{{ $timezone }}</option>@endforeach</select></label><p class="muted">Used for farm dates and date validation.</p><x-field name="fresh_stock_alert_kg" label="Fresh-stock alert threshold (kg)" type="number" step="0.001" min="0" max="1000000" :value="$farm->fresh_stock_alert_kg" /><p class="muted">Leave blank to disable low-stock alerts. An alert appears when fresh stock reaches or falls below this threshold.</p></div>
            </x-card>
            <x-card title="Farm mode & features">
                <div class="space-y-5"><label>Farm mode<select name="mode" x-model="mode" required><option value="simple">Simple</option><option value="advanced">Advanced</option></select></label><p class="muted">Simple: quick production and harvest entry, inventory, packet packaging and sales. Advanced: locations, detailed batches and production reports. An Owner-only farm works in either mode. Switching modes preserves records and optional user accounts.</p><div x-show="mode === 'advanced'" class="space-y-5"><x-toggle name="batch_tracking" label="Batch tracking" :checked="$farm->batch_tracking" x-model="tracking" description="Show batch references and cycle details. Internal production IDs are kept in both modes." /><x-toggle name="qr_printing" label="QR label printing (optional)" :checked="$farm->qr_printing" description="Generate labels only in Advanced mode when batch tracking and this setting are enabled." /></div><p x-cloak x-show="mode === 'simple' || !tracking" class="rounded-xl bg-stone-50 p-3 text-sm text-stone-600">Batch codes and QR printing are currently hidden. Existing references, locations and costs are retained.</p><x-toggle name="grocery_sales" label="Grocery sales" :checked="$farm->grocery_sales" description="Enable grocery invoices for retail packets and other sellable products in either mode." /></div>
            </x-card>
        </div>
    </div>
    <div class="grid items-start gap-6 xl:grid-cols-2">
        <x-card title="Enabled roles" description="Optional roles can be switched off without removing accounts or history.">
            <input type="hidden" name="enabled_roles[]" value="owner">
            <x-table label="Enabled farm roles"><x-slot:head><tr><th scope="col">Role</th><th scope="col">Access</th></tr></x-slot:head>
                @foreach($roles as $role)<tr><td class="font-medium">{{ ucfirst($role->name) }}</td><td>@if($role->name === 'owner')<span class="badge">Always enabled</span>@else<label class="flex items-center gap-3"><input class="size-4 accent-emerald-700" type="checkbox" name="enabled_roles[]" value="{{ $role->name }}" @checked(in_array($role->name, old('enabled_roles', $roles->where('is_active', true)->pluck('name')->all())))><span>Enabled</span></label>@endif</td></tr>@endforeach
            </x-table>
            <p class="muted mt-4">At least one active Owner is required. Manage individual accounts under <a class="text-emerald-800 underline" href="{{ route('users.index') }}">Users</a>.</p>
        </x-card>
        <x-card title="Mushroom varieties" description="Enter your own varieties, one per line. No varieties are added automatically.">
            <label>Varieties<textarea name="mushroom_varieties" rows="7" maxlength="5000">{{ old('mushroom_varieties', implode("\n", $farm->mushroom_varieties ?? [])) }}</textarea></label><p class="muted mt-3">Up to 50 unique varieties, 100 characters per name. These become suggestions in production forms; historical records are preserved.</p>
        </x-card>
    </div>
    <div class="flex flex-wrap items-center justify-end gap-3 rounded-2xl border border-stone-200 bg-white p-4"><x-button :href="route('dashboard')" variant="secondary">Back to dashboard</x-button><x-button>Save farm settings</x-button></div>
</x-form>
@endsection
