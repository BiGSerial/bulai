{{-- Medidor de uma razão (0–1) contra o total: trilha e preenchimento da mesma rampa. --}}
@props(['label', 'rate' => null, 'hint' => null])
@php
    $percent = $rate === null ? null : round((float) $rate * 100, 1);
    $widthClass = match ($percent === null ? 0 : (int) ceil($percent / 100 * 12)) {
        0 => 'w-0', 1 => 'w-1/12', 2 => 'w-2/12', 3 => 'w-3/12', 4 => 'w-4/12', 5 => 'w-5/12', 6 => 'w-6/12',
        7 => 'w-7/12', 8 => 'w-8/12', 9 => 'w-9/12', 10 => 'w-10/12', 11 => 'w-11/12', default => 'w-full',
    };
    $display = $percent === null ? '—' : number_format($percent, 1, ',', '.').'%';
@endphp
<div {{ $attributes->class('grid gap-1') }}>
    <div class="flex items-baseline justify-between text-sm"><span class="text-slate-700">{{ $label }}</span><span class="font-semibold tabular-nums text-slate-900">{{ $display }}</span></div>
    <div class="h-2.5 w-full rounded-full bg-brand-100" role="img" aria-label="{{ $label }}: {{ $display }}"><div class="h-full rounded-full bg-brand-600 {{ $widthClass }}"></div></div>
    @if ($hint)<p class="text-xs text-slate-500">{{ $hint }}</p>@endif
</div>
