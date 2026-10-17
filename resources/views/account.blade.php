@extends('layouts.app')
@section('content')
<div class="card max-w-xl">
    <p class="muted">{{ ucfirst(auth()->user()->role) }} account</p>
    <h1 class="mt-2 text-2xl font-semibold">Welcome, {{ auth()->user()->name }}</h1>
    <p class="muted mt-3">Choose an available task to get started. Contact an Owner to change your access.</p>
    <div class="mt-6 flex flex-wrap gap-3">
        @foreach(['production' => 'Production', 'harvests' => 'Harvests', 'sales' => 'Sales'] as $key => $label)
            @can('access-section', $key)<a class="btn" href="{{ route('farm.section', $key) }}">{{ $label }}</a>@endcan
        @endforeach
    </div>
</div>
@endsection
