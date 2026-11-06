{{--
    Folha de ações (bottom sheet) controlada por Alpine: o pai declara `x-data="{ folha: false }"` e usa `modelo="folha"`.
    Sobe do rodapé no celular, centralizada no desktop; fecha com Esc, toque fora ou "Fechar".
--}}
@props(['modelo' => 'folha', 'titulo' => null])
<div x-cloak x-show="{{ $modelo }}" x-on:keydown.escape.window="{{ $modelo }} = false" class="fixed inset-0 z-40 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" @if ($titulo) aria-label="{{ $titulo }}" @endif>
    <div class="absolute inset-0 bg-slate-900/50" x-show="{{ $modelo }}" x-transition.opacity x-on:click="{{ $modelo }} = false" aria-hidden="true"></div>
    <div class="relative grid w-full max-w-md gap-2 rounded-t-3xl bg-white p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-2xl sm:rounded-3xl sm:p-5"
         x-show="{{ $modelo }}" x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-full sm:translate-y-4 sm:opacity-0" x-transition:enter-end="translate-y-0 sm:opacity-100" x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="translate-y-0 sm:opacity-100" x-transition:leave-end="translate-y-full sm:translate-y-4 sm:opacity-0" x-trap.noscroll="{{ $modelo }}">
        <span class="mx-auto mb-1 block h-1.5 w-12 rounded-full bg-slate-300 sm:hidden" aria-hidden="true"></span>
        @if ($titulo)<p class="break-words px-1 text-xl font-semibold leading-snug text-brand-900">{{ $titulo }}</p>@endif
        {{ $slot }}
        <button type="button" class="mt-1 min-h-14 w-full rounded-2xl bg-slate-100 px-4 text-lg font-semibold text-slate-800 hover:bg-slate-200 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" x-on:click="{{ $modelo }} = false">Fechar</button>
    </div>
</div>
