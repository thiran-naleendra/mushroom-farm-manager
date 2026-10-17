@props(['title' => null, 'description' => null])
<section {{ $attributes->class(['card']) }}>
    @if($title)<h2 class="text-lg font-semibold">{{ $title }}</h2>@endif
    @if($description)<p class="muted mt-2">{{ $description }}</p>@endif
    <div @class(['mt-6' => $title || $description])>{{ $slot }}</div>
</section>
