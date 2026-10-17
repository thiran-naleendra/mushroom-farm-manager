@props(['action', 'method' => 'POST', 'uploads' => false])
<form action="{{ $action }}" method="{{ strtoupper($method) === 'GET' ? 'GET' : 'POST' }}" @if($uploads) enctype="multipart/form-data" @endif {{ $attributes }}>
    @if(strtoupper($method) !== 'GET') @csrf @endif
    @if(!in_array(strtoupper($method), ['GET', 'POST'])) @method($method) @endif
    {{ $slot }}
</form>
