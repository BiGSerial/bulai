{{--
    Busca global do cabeçalho: campo com resultados agrupados (Telas, Documentos, Catálogo ANVISA, Usuários).
    Atalho Ctrl/⌘+K foca o campo; setas percorrem os resultados, Enter abre, Esc fecha.
--}}
<div class="relative" data-testid="global-search"
     x-data="{ open: false, active: -1,
        items() { return Array.from($el.querySelectorAll('[data-result]')) },
        move(delta) { const items = this.items(); if (items.length === 0) { return } this.active = (this.active + delta + items.length) % items.length; items[this.active].scrollIntoView({ block: 'nearest' }) },
        go() { const items = this.items(); if (this.active >= 0 && items[this.active]) { items[this.active].click() } },
        focus() { this.open = true; $nextTick(() => $refs.campo.focus()) } }"
     x-on:keydown.window.prevent.ctrl.k="focus()" x-on:keydown.window.prevent.meta.k="focus()"
     x-on:click.outside="open = false" x-on:keydown.escape="open = false; $refs.campo.blur()">
    <label class="sr-only" for="busca-global">Buscar telas, documentos, medicamentos e usuários</label>
    <button type="button" class="inline-flex size-10 items-center justify-center rounded-lg text-slate-700 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 md:hidden" x-on:click="focus()" aria-label="Buscar" title="Buscar (Ctrl+K)">
        <svg class="size-6 stroke-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="{{ \App\AdminNavigation::icon('search') }}" /></svg>
    </button>
    <div class="relative hidden md:block" x-bind:class="open ? '!block' : ''">
        <svg class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 stroke-2 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="{{ \App\AdminNavigation::icon('search') }}" /></svg>
        <input id="busca-global" x-ref="campo" type="search" autocomplete="off" class="ui-control h-9 w-48 pl-8 pr-2 py-1.5 text-sm transition-[width] focus:w-72 lg:w-64 lg:focus:w-96" placeholder="Buscar telas, documentos, medicamentos, usuários… (Ctrl+K)"
               wire:model.live.debounce.250ms="q" x-on:focus="open = true" x-on:input="open = true; active = -1" x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)" x-on:keydown.enter.prevent="go()"
               role="combobox" aria-autocomplete="list" aria-controls="busca-global-resultados" x-bind:aria-expanded="open">
    </div>
    <div id="busca-global-resultados" x-cloak x-show="open && $wire.q.trim().length >= 2" x-transition.opacity role="listbox" aria-label="Resultados"
         class="absolute right-0 z-30 mt-1 max-h-96 w-80 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl sm:w-96">
        <div wire:loading.delay class="px-3 py-2 text-xs text-slate-500">Buscando…</div>
        <div wire:loading.remove>
            @forelse ($groups as $group)
                <p class="px-3 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $group['label'] }}</p>
                @foreach ($group['items'] as $item)
                    <a data-result role="option" wire:key="gs-{{ $loop->parent->index }}-{{ $loop->index }}" class="block rounded-lg px-3 py-1.5 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" x-bind:class="active === items().indexOf($el) ? 'bg-brand-50' : ''" x-on:mouseenter="active = items().indexOf($el)" href="{{ $item['href'] }}" wire:navigate x-on:click="open = false">
                        <span class="block truncate text-sm font-medium text-slate-900">{{ $item['title'] }}</span>
                        @if ($item['support'])<span class="block truncate text-xs text-slate-500">{{ $item['support'] }}</span>@endif
                    </a>
                @endforeach
            @empty
                <p class="px-3 py-2 text-sm text-slate-600">Nada encontrado para “{{ $q }}”.</p>
            @endforelse
        </div>
    </div>
</div>
