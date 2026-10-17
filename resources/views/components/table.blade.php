@props(['label' => 'Data table'])
<div {{ $attributes->class(['overflow-x-auto rounded-xl border border-stone-200']) }} tabindex="0" role="region" aria-label="{{ $label }}">
    <table class="data-table"><thead>{{ $head }}</thead><tbody>{{ $slot }}</tbody></table>
</div>
