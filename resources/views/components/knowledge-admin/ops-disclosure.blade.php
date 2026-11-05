{{-- Seção recolhível (detalhe sob demanda, princípios de UX, §2): título, resumo curto e conteúdo que só aparece ao abrir. --}}
@props(['title', 'summary' => null, 'open' => false])
<details {{ $attributes->class('group rounded-xl border border-slate-200 bg-white shadow-sm') }} @if ($open) open @endif>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-xl px-4 py-3 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 sm:px-5">
        <span class="min-w-0">
            <span class="block text-base font-semibold text-slate-900">{{ $title }}</span>
            @if ($summary)<span class="block truncate text-sm text-slate-600">{{ $summary }}</span>@endif
        </span>
        <span class="shrink-0 text-xs font-medium text-brand-700"><span class="group-open:hidden">Ver detalhes</span><span class="hidden group-open:inline">Recolher</span></span>
    </summary>
    <div class="grid gap-4 border-t border-slate-200 px-4 py-4 sm:px-5">{{ $slot }}</div>
</details>
