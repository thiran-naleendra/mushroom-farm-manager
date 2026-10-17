@props(['href' => null, 'variant' => 'primary', 'type' => 'submit'])
@php($style = $variant === 'secondary' ? 'btn-secondary' : 'btn')
@if($href)<a href="{{ $href }}" {{ $attributes->class([$style]) }}>{{ $slot }}</a>@else<button type="{{ $type }}" {{ $attributes->class([$style]) }}>{{ $slot }}</button>@endif
