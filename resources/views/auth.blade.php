@extends('layouts.app')
@section('content')
<div class="mx-auto max-w-md py-8 sm:py-16">
    <div class="mb-8 text-center"><span class="inline-block rounded-2xl bg-emerald-800 p-4 text-xl font-bold text-white">MF</span><h1 class="mt-5 text-3xl font-semibold tracking-tight">{{ $setup ? 'Your farm starts here' : 'Welcome back' }}</h1><p class="muted mt-3">{{ $setup ? 'Set up your farm and owner account.' : 'Sign in to manage your mushroom farm.' }}</p></div>
    <form method="POST" action="{{ $setup ? route('setup') : route('login') }}" class="card space-y-5">
        @csrf
        @if($setup)
            <x-field name="farm_name" label="Farm name" required maxlength="120" />
            <x-field name="name" label="Your name" required maxlength="120" autocomplete="name" />
        @endif
        <x-field name="email" label="Email address" type="email" required autocomplete="email" />
        <label>Password<input name="password" type="password" required autocomplete="{{ $setup ? 'new-password' : 'current-password' }}" @if($setup) minlength="10" @endif></label>
        @if($setup)
            <label>Confirm password<input name="password_confirmation" type="password" required autocomplete="new-password"></label>
            <label>Farm mode<select name="mode"><option value="simple" @selected(old('mode') === 'simple')>Simple — everyday farm management</option><option value="advanced" @selected(old('mode') === 'advanced')>Advanced — batch tracking</option></select></label>
            <label>Currency<select name="currency">@foreach(['LKR', 'USD', 'EUR', 'GBP', 'INR'] as $currency)<option @selected(old('currency', 'LKR') === $currency)>{{ $currency }}</option>@endforeach</select></label>
        @endif
        <button class="btn w-full">{{ $setup ? 'Create my farm' : 'Sign in' }}</button>
    </form>
</div>
@endsection
