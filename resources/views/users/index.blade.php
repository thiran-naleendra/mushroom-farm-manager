@extends('layouts.app')
@section('content')
<header class="mb-8"><h1 class="text-3xl font-semibold">Users &amp; roles</h1><p class="muted mt-2">An Owner account is all a small farm needs. Add other accounts when needed.</p></header>
<section class="mb-6 grid gap-4 md:grid-cols-3" aria-label="Role access">
    @foreach($roles as $role)
        <div class="card"><h2 class="font-semibold">{{ ucfirst($role->name) }}</h2><p class="muted mt-2">{{ $role->is_active ? 'Active' : 'Inactive' }}</p>
            @if($role->name === 'owner')<p class="muted mt-4">Mandatory. At least one active Owner must remain.</p>@else
                <form method="POST" action="{{ route('roles.update', $role->name) }}" class="mt-4">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $role->is_active ? 0 : 1 }}"><button class="btn">{{ $role->is_active ? 'Deactivate' : 'Activate' }} {{ ucfirst($role->name) }} role</button></form>
            @endif
        </div>
    @endforeach
</section>
<div class="grid items-start gap-6 xl:grid-cols-3">
    <section class="card xl:col-span-1" x-data="{ role: @js(old('role', 'worker')) }"><h2 class="mb-5 text-lg font-semibold">Create user</h2><form method="POST" action="{{ route('users.store') }}" class="space-y-5">@csrf @include('users.form', ['account' => null])</form></section>
    <section class="card xl:col-span-2"><h2 class="mb-5 text-lg font-semibold">Farm accounts</h2>
        <p class="muted mb-5">Deactivation preserves accounts and history. Inactive users and users with inactive roles cannot sign in or continue using the app.</p>
        @foreach($users as $account)
            <article class="flex flex-wrap items-center justify-between gap-4 border-t border-stone-100 py-5"><div class="min-w-0"><h3 class="font-semibold">{{ $account->name }} @if($account->id === auth()->id())<span class="muted">(you)</span>@endif</h3><p class="muted mt-1 break-all">{{ $account->email }}</p><p class="muted mt-2">{{ ucfirst($account->role) }} · Account {{ $account->is_active ? 'active' : 'inactive' }} · Role {{ $roles->firstWhere('name', $account->role)?->is_active ? 'active' : 'inactive' }}</p></div><a class="btn" href="{{ route('users.edit', $account) }}">Manage<span class="sr-only"> {{ $account->name }}</span></a></article>
        @endforeach
        <div class="mt-4">{{ $users->links() }}</div>
    </section>
</div>
@endsection
