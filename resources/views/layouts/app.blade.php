<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $farm->name ?? 'Mushroom Farm Manager' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="min-h-screen" x-data="adminLayout" @resize.window="desktop = window.matchMedia('(min-width: 1024px)').matches; if (desktop) mobile = false" @keydown.escape.window="mobile = false; userMenu = false" x-effect="document.body.style.overflow = mobile && !desktop ? 'hidden' : ''">
    @isset($farm)
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[70] focus:rounded-lg focus:bg-white focus:p-3">Skip to content</a>
        <button x-cloak x-show="mobile && !desktop" @click="mobile = false" class="fixed inset-0 z-40 bg-stone-950/40 lg:hidden" aria-label="Close navigation"></button>
        <aside id="navigation" x-ref="sidebar" @keydown="trapNavigation($event)" :inert="!desktop && !mobile" class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-r border-emerald-900 bg-emerald-950 text-white transition-[width,transform] duration-200 motion-reduce:transition-none lg:translate-x-0" :class="[mobile ? 'translate-x-0' : '-translate-x-full', collapsed ? 'lg:w-20' : 'lg:w-64']" aria-label="Main navigation">
            <div class="flex h-20 shrink-0 items-center gap-3 border-b border-white/10 px-4">
                <a href="{{ route('dashboard') }}" class="flex min-w-0 flex-1 items-center gap-3" title="Dashboard">
                    @if($farm->logo_path)<img src="{{ route('farm.logo') }}" alt="" class="size-10 shrink-0 rounded-xl bg-white object-contain p-1">@else<span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-800 text-sm font-bold">MF</span>@endif
                    <span class="truncate text-sm font-semibold" :class="collapsed ? 'lg:hidden' : ''">Farm Manager</span>
                </a>
                <button type="button" x-ref="closeNavigation" @click="mobile = false" class="rounded-lg p-2 hover:bg-white/10 lg:hidden" aria-label="Close navigation">&times;</button>
            </div>
            <nav class="min-h-0 flex-1 space-y-1 overflow-y-auto px-3 py-4">
                @foreach(config('navigation') as $key => $item)
                    @can('access-section', $key)
                        @if(isset($item['route']))
                            <a href="{{ $item['route'] === 'farm.section' ? route($item['route'], $key) : route($item['route']) }}" title="{{ $item['label'] }}" @if(($section ?? '') === $key) aria-current="page" @endif class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium {{ ($section ?? '') === $key ? 'bg-emerald-800 text-white' : 'text-emerald-100 hover:bg-white/10' }}">
                                <span class="flex size-6 shrink-0 items-center justify-center rounded-md border border-white/20 text-xs" aria-hidden="true">{{ strtoupper(substr($item['label'], 0, 2)) }}</span><span :class="collapsed ? 'lg:hidden' : ''">{{ $item['label'] }}</span>
                            </a>
                        @else
                            <span aria-disabled="true" title="{{ $item['label'] }} — module not available yet" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-emerald-100/40"><span class="flex size-6 shrink-0 items-center justify-center rounded-md border border-white/10 text-xs" aria-hidden="true">{{ strtoupper(substr($item['label'], 0, 2)) }}</span><span class="flex flex-1 items-center justify-between gap-2" :class="collapsed ? 'lg:hidden' : ''">{{ $item['label'] }}<span class="text-[10px]">Soon</span></span></span>
                        @endif
                    @endcan
                @endforeach
            </nav>
            <div class="shrink-0 border-t border-white/10 p-3"><button type="button" @click="collapsed = !collapsed" :aria-expanded="!collapsed" aria-controls="navigation" class="hidden w-full items-center gap-3 rounded-xl px-3 py-3 text-sm text-emerald-100 hover:bg-white/10 lg:flex"><span aria-hidden="true" x-text="collapsed ? '»' : '«'" class="w-6 shrink-0 text-center text-lg"></span><span :class="collapsed ? 'lg:hidden' : ''">Collapse sidebar</span><span class="sr-only" x-show="collapsed">Expand sidebar</span></button><p class="px-3 pb-2 text-xs text-emerald-200/70" :class="collapsed ? 'lg:hidden' : ''">{{ ucfirst($farm->mode) }} mode</p></div>
        </aside>
    @endisset
    <div class="min-w-0 transition-[padding] duration-200 motion-reduce:transition-none" @isset($farm) :class="collapsed ? 'lg:pl-20' : 'lg:pl-64'" @endisset>
        @isset($farm)
            <header class="sticky top-0 z-30 flex min-h-20 items-center justify-between gap-3 border-b border-stone-200 bg-white/95 px-4 py-3 backdrop-blur sm:px-8">
                <div class="flex min-w-0 items-center gap-3"><button type="button" x-ref="openNavigation" @click="mobile = true" :aria-expanded="mobile" aria-controls="navigation" class="rounded-xl border border-stone-200 p-2.5 lg:hidden" aria-label="Open navigation"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button><div class="min-w-0"><p class="truncate text-sm font-semibold sm:text-base">{{ $farm->name }}</p><p class="muted text-xs">Mushroom Farm Manager</p></div></div>
                <div class="relative shrink-0" @click.outside="userMenu = false"><button type="button" @click="userMenu = !userMenu" :aria-expanded="userMenu" aria-controls="user-menu" class="flex items-center gap-2 rounded-xl border border-stone-200 p-2 text-sm hover:bg-stone-50" aria-label="User menu"><span class="flex size-8 items-center justify-center rounded-full bg-emerald-100 font-semibold text-emerald-900">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span><span class="hidden max-w-40 truncate sm:block">{{ auth()->user()->name }}</span><span aria-hidden="true">⌄</span></button>
                    <div id="user-menu" x-cloak x-show="userMenu" x-transition class="absolute right-0 mt-2 w-64 rounded-xl border border-stone-200 bg-white p-3 shadow-lg"><p class="truncate px-2 py-1 text-sm font-semibold">{{ auth()->user()->name }}</p><p class="truncate px-2 text-xs text-stone-500">{{ auth()->user()->email }}</p><p class="px-2 py-2 text-xs text-emerald-700">{{ ucfirst(auth()->user()->role) }}</p>@can('manage-farm')<a class="block rounded-lg px-2 py-2 text-sm hover:bg-stone-50" href="{{ route('settings.edit') }}">Farm settings</a><a class="block rounded-lg px-2 py-2 text-sm hover:bg-stone-50" href="{{ route('users.index') }}">Manage users</a>@endcan<x-form :action="route('logout')"><button class="mt-2 w-full rounded-lg border-t border-stone-100 px-2 py-2 text-left text-sm text-red-700 hover:bg-red-50">Sign out</button></x-form></div>
                </div>
            </header>
        @endisset
        <main id="main-content" class="mx-auto max-w-7xl p-4 sm:p-8 lg:p-10">
            @if(session('success'))<div role="status" class="mb-6 rounded-xl bg-emerald-100 p-4 text-sm text-emerald-900">{{ session('success') }}</div>@endif
            @if($errors->any())<div role="alert" class="mb-6 rounded-xl bg-red-50 p-4 text-sm text-red-800"><ul class="list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
