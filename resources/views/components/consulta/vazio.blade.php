{{-- Estado vazio com ilustração leve (SVG inline), uma frase e as ações no slot. icon: nome do x-consulta.icone --}}
@props(['titulo', 'texto' => null, 'icon' => 'info', 'tone' => 'brand'])
<div {{ $attributes->class('grid justify-items-center gap-3 rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-8 text-center') }}>
    <span class="relative inline-flex" aria-hidden="true">
        <svg class="size-28 text-surface-200" viewBox="0 0 120 120" fill="currentColor"><circle cx="60" cy="60" r="56" /><circle cx="22" cy="30" r="6" class="text-accent-200" fill="currentColor" /><circle cx="98" cy="92" r="4" class="text-brand-200" fill="currentColor" /></svg>
        <x-consulta.icone :name="$icon" :tone="$tone" size="xl" class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 shadow-sm" />
    </span>
    <p class="text-xl font-semibold leading-snug text-slate-900">{{ $titulo }}</p>
    @if ($texto)<p class="max-w-md text-slate-700">{{ $texto }}</p>@endif
    @if (trim((string) $slot) !== '')<div class="grid w-full max-w-sm gap-2 pt-1">{{ $slot }}</div>@endif
</div>
