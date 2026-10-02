@props(['label', 'name' => null, 'hint' => null, 'required' => false, 'optional' => false])
<label {{ $attributes->class('grid content-start gap-1.5 text-sm font-medium text-slate-800') }}>
    <span>{{ $label }}@if ($required)<span class="text-rose-700" aria-hidden="true"> *</span>@elseif ($optional)<span class="font-normal text-slate-500"> (opcional)</span>@endif</span>
    {{ $slot }}
    @if ($hint)<span class="text-sm font-normal text-slate-500">{{ $hint }}</span>@endif
    @if ($name)@error($name)<span class="text-sm font-normal text-rose-700">{{ $message }}</span>@enderror @endif
</label>
