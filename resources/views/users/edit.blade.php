@extends('layouts.app')
@section('content')
<a class="text-sm text-emerald-800" href="{{ route('users.index') }}">&larr; Users &amp; roles</a>
<h1 class="mb-6 mt-4 text-3xl font-semibold">Manage {{ $account->name }}</h1>
<form method="POST" action="{{ route('users.update', $account) }}" class="card max-w-xl space-y-5" x-data="{ role: @js(old('role', $account->role)) }">@csrf @method('PUT') @include('users.form')</form>
@endsection
