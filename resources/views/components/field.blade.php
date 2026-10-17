@props(['name', 'label', 'type' => 'text', 'value' => '', 'step' => null])
<label>{{ $label }}<input name="{{ $name }}" type="{{ $type }}" value="{{ old($name, $value) }}" @if($step) step="{{ $step }}" @endif @error($name) aria-invalid="true" @enderror {{ $attributes }}>@error($name)<span class="block text-xs font-normal text-red-700">{{ $message }}</span>@enderror</label>
