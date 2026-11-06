{{-- Seção recolhível (acordeão) com ícone e título grandes. open: aberta por padrão. tone colore a faixa do ícone. --}}
@props(['titulo', 'icon' => 'info', 'tone' => 'brand', 'open' => false, 'resumo' => null])
<details {{ $attributes->class('group min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm') }} @if ($open) open @endif>
    <summary class="flex min-h-16 cursor-pointer select-none items-center gap-3 px-4 py-3 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-600 [&::-webkit-details-marker]:hidden">
        <x-consulta.icone :name="$icon" :tone="$tone" />
        <span class="min-w-0 flex-1">
            <span class="block break-words text-xl font-semibold leading-snug text-brand-900">{{ $titulo }}</span>
            @if ($resumo)<span class="block break-words text-base text-slate-600">{{ $resumo }}</span>@endif
        </span>
        <svg class="size-7 shrink-0 text-slate-500 transition group-open:rotate-180" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
    </summary>
    <div class="grid min-w-0 gap-3 border-t border-slate-100 p-4 sm:p-5">{{ $slot }}</div>
</details>
