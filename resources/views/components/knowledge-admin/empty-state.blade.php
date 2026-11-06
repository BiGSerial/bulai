{{-- Lista vazia, compacta: ícone, uma frase (slot) e, se houver, uma ação. Sem blocos altos em branco. --}}
@props(['icon' => true])
<div {{ $attributes->class('flex flex-col items-center gap-2 rounded-lg border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-sm text-slate-600') }}>
    @if ($icon)<svg class="size-6 stroke-2 text-slate-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" /></svg>@endif
    <div class="grid justify-items-center gap-1 [&_.mt-4]:mt-1">{{ $slot }}</div>
</div>
