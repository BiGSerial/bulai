<div wire:poll.60s="verificar" class="shrink-0">
    <a class="relative inline-flex size-14 items-center justify-center rounded-xl text-brand-800 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('consulta.avisos') }}" wire:navigate aria-label="Avisos, {{ $naoLidos }} {{ $naoLidos === 1 ? 'não lido' : 'não lidos' }}" @if (request()->routeIs('consulta.avisos')) aria-current="page" @endif>
        <svg class="size-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
        @if ($naoLidos > 0)
            <span class="absolute -right-0.5 -top-0.5 inline-flex min-w-7 items-center justify-center rounded-full bg-accent-600 px-1.5 py-0.5 text-base font-bold leading-none text-white" aria-hidden="true">{{ $naoLidos > 9 ? '9+' : $naoLidos }}</span>
        @endif
    </a>
</div>
