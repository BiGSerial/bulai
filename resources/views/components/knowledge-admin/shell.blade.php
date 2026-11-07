@props(['title', 'subtitle' => null, 'back' => null, 'backLabel' => 'Voltar'])
@php
    $pendingCount = \App\Models\Knowledge\IngestionBatchItem::query()->where('status', \App\Models\Knowledge\IngestionBatchItem::STATUS_PENDING)->count();
    // Grupos do menu (rota, rótulo, padrão de rota ativa, ícone, dica e descrição): fonte única em App\AdminNavigation.
    $groups = \App\AdminNavigation::groups();
    $routeName = request()->route()?->getName();
    $place = \App\AdminNavigation::find($routeName);
    $help = \App\AdminHelp::for($routeName);
    $user = auth()->user();
    $initials = \Illuminate\Support\Str::of((string) $user?->name)->explode(' ')->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->join('') ?: 'A';
@endphp
<div class="min-h-screen bg-slate-200 text-slate-900" data-realtime-url="{{ route('admin.knowledge.stream') }}"
     x-data="{ drawer: false, menu: false, help: false, update: false, collapsed: (() => { try { return localStorage.getItem('bulai-admin-collapsed') === '1' } catch (e) { return false } })() }"
     x-on:bulai-sw-update.window="update = true" x-on:keydown.escape.window="drawer = false; menu = false; help = false"
     x-on:livewire:navigated.document="drawer = false; menu = false; help = false"
     x-on:bulai-ajuda.window="help = true"
     x-effect="try { localStorage.setItem('bulai-admin-collapsed', collapsed ? '1' : '0') } catch (e) {}">
    <a class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-2 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2 focus:text-sm focus:font-medium focus:text-brand-800 focus:shadow-sm" href="#conteudo">Ir para o conteúdo</a>

    {{-- Barra lateral (desktop): fixa, recolhível para só ícones; preferência guardada no navegador. --}}
    <aside class="fixed inset-y-0 left-0 z-30 hidden flex-col bg-brand-900 text-brand-100 shadow-xl lg:flex" x-bind:class="collapsed ? 'w-16' : 'w-64'" aria-label="Menu da administração">
        <a class="flex h-14 shrink-0 items-center gap-2 px-3 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-400" href="{{ route('admin.knowledge.dashboard') }}" wire:navigate aria-label="BULAI — visão geral">
            <img class="size-9 shrink-0 rounded-lg bg-white p-1" src="/brand/bulai-simbolo.svg" alt="">
            <span class="truncate text-base font-semibold tracking-wide text-white" x-show="!collapsed" x-cloak>BULAI <span class="text-xs font-normal text-brand-300">administração</span></span>
        </a>
        <x-knowledge-admin.sidebar-nav class="flex-1" :groups="$groups" :pending-count="$pendingCount" collapsed="collapsed" />
        <div class="shrink-0 border-t border-brand-700 bg-brand-900 p-2">
            <button type="button" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-brand-200 hover:bg-brand-700/60 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-400" x-on:click="collapsed = !collapsed" x-bind:aria-expanded="!collapsed" x-bind:title="collapsed ? 'Expandir menu' : 'Recolher menu'">
                <svg class="size-5 shrink-0 transition stroke-2" x-bind:class="collapsed ? 'rotate-180' : ''" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="M18.75 19.5l-7.5-7.5 7.5-7.5m-6 15L5.25 12l7.5-7.5" /></svg>
                <span x-show="!collapsed" x-cloak>Recolher menu</span>
            </button>
        </div>
    </aside>

    {{-- Gaveta (celular e tablet): mesmo menu, com foco preso e fechamento por Esc, toque fora ou navegação. --}}
    <div x-cloak x-show="drawer" class="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true" aria-label="Menu da administração">
        <div class="absolute inset-0 bg-slate-900/60" x-on:click="drawer = false" aria-hidden="true"></div>
        <aside class="absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col bg-brand-900 text-brand-100 shadow-xl" x-trap.noscroll="drawer" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0">
            <div class="flex h-14 shrink-0 items-center justify-between gap-2 px-3">
                <span class="flex items-center gap-2"><img class="size-9 rounded-lg bg-white p-1" src="/brand/bulai-simbolo.svg" alt=""><span class="text-base font-semibold text-white">BULAI</span></span>
                <button type="button" class="inline-flex size-10 items-center justify-center rounded-lg text-brand-100 hover:bg-brand-700/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-400" x-on:click="drawer = false" aria-label="Fechar menu"><svg class="size-5 stroke-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="M6 18 18 6M6 6l12 12" /></svg></button>
            </div>
            <x-knowledge-admin.sidebar-nav class="flex-1" :groups="$groups" :pending-count="$pendingCount" collapsed="false" />
        </aside>
    </div>

    {{-- Painel "Ajuda desta tela": para que serve, quando usar, passo a passo e telas relacionadas (App\AdminHelp). --}}
    <div x-cloak x-show="help" class="fixed inset-0 z-40" role="dialog" aria-modal="true" aria-labelledby="ajuda-titulo">
        <div class="absolute inset-0 bg-slate-900/40" x-on:click="help = false" aria-hidden="true"></div>
        <aside class="absolute inset-y-0 right-0 flex w-96 max-w-[92vw] flex-col bg-white shadow-xl" x-trap.noscroll="help" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" data-testid="help-panel">
            <div class="flex h-14 shrink-0 items-center justify-between gap-2 border-b border-slate-200 px-4">
                <h2 id="ajuda-titulo" class="text-sm font-semibold text-slate-900">Ajuda desta tela</h2>
                <button type="button" class="inline-flex size-9 items-center justify-center rounded-lg text-slate-600 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" x-on:click="help = false" aria-label="Fechar ajuda"><svg class="size-5 stroke-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="M6 18 18 6M6 6l12 12" /></svg></button>
            </div>
            <div class="grid flex-1 content-start gap-4 overflow-y-auto px-4 py-4 text-sm text-slate-700">
                @if ($help)
                    <div><p class="text-base font-semibold text-slate-900">{{ $help['title'] }}</p></div>
                    <div class="grid gap-1"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Para que serve</p><p>{{ $help['purpose'] }}</p></div>
                    <div class="grid gap-1"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Quando usar</p><p>{{ $help['when'] }}</p></div>
                    <div class="grid gap-1">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Passo a passo</p>
                        <ol class="grid gap-1.5">
                            @foreach ($help['steps'] as $step)
                                <li class="flex gap-2"><span class="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-800 tabular-nums" aria-hidden="true">{{ $loop->iteration }}</span><span>{{ $step }}</span></li>
                            @endforeach
                        </ol>
                    </div>
                    @if ($help['related'] !== [])
                        <div class="grid gap-1">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Telas relacionadas</p>
                            <ul class="flex flex-wrap gap-1.5">
                                @foreach ($help['related'] as $link)
                                    <li><a class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-brand-50 hover:text-brand-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route($link['route']) }}" wire:navigate>{{ $link['label'] }}</a></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @else
                    <p>Esta tela ainda não tem ajuda específica. Use o menu à esquerda: cada item traz uma descrição curta do que faz.</p>
                @endif
                <div class="grid gap-1 border-t border-slate-200 pt-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Fluxo das bulas</p>
                    <ol class="grid gap-1">
                        @foreach (\App\AdminHelp::workflow() as $step)
                            <li class="flex items-center gap-2"><span class="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-700 tabular-nums" aria-hidden="true">{{ $loop->iteration }}</span><a class="font-medium text-brand-700 hover:underline" href="{{ route($step['route']) }}" wire:navigate>{{ $step['label'] }}</a><span class="truncate text-xs text-slate-500">{{ $step['hint'] }}</span></li>
                        @endforeach
                    </ol>
                </div>
            </div>
        </aside>
    </div>

    <div class="flex min-h-screen min-w-0 flex-col transition-[padding]" x-bind:class="collapsed ? 'lg:pl-16' : 'lg:pl-64'">
        <div x-cloak x-show="update" role="status" class="flex items-center justify-center gap-3 bg-brand-700 px-4 py-2 text-sm text-white">
            Há uma nova versão do aplicativo.
            <button type="button" class="rounded-lg bg-white px-3 py-1 font-semibold text-brand-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-white" x-on:click="window.bulaiApplyUpdate()">Atualizar agora</button>
        </div>

        <header class="sticky top-0 z-20 border-b border-slate-300 bg-white/95 shadow-sm backdrop-blur">
            <div class="flex h-14 items-center gap-2 px-4 sm:px-6">
                <button type="button" class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg text-slate-700 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 lg:hidden" x-on:click="drawer = true" aria-label="Abrir menu" aria-haspopup="dialog"><svg class="size-6 stroke-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg></button>
                <a class="inline-flex shrink-0 items-center lg:hidden" href="{{ route('admin.knowledge.dashboard') }}" wire:navigate aria-label="BULAI — visão geral"><img class="h-8 w-auto" src="/brand/bulai-logo-horizontal-transparente.svg" alt="BULAI"></a>
                {{-- Trilha: Grupo › Tela (a partir do menu). --}}
                <nav class="hidden min-w-0 items-center gap-1 text-sm text-slate-600 lg:flex" aria-label="Trilha">
                    @if ($place)
                        <span class="truncate">{{ $place['group'] }}</span>
                        <svg class="size-4 shrink-0 stroke-2 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                        @if ($place['item']['label'] !== $title)
                            <a class="truncate font-medium text-slate-700 hover:text-brand-700 hover:underline" href="{{ route($place['item']['route']) }}" wire:navigate>{{ $place['item']['label'] }}</a>
                            <svg class="size-4 shrink-0 stroke-2 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                        @endif
                    @endif
                    <span class="truncate font-medium text-slate-900" aria-current="page">{{ $title }}</span>
                </nav>
                <div class="ml-auto flex min-w-0 shrink items-center gap-1 sm:gap-2">
                    <livewire:admin.global-search />
                    <div class="hidden xl:block"><livewire:admin.knowledge.service-health /></div>
                    <button type="button" class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg text-slate-700 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" x-on:click="help = true" x-bind:aria-expanded="help" aria-haspopup="dialog" aria-label="Ajuda desta tela" title="Ajuda desta tela"><svg class="size-6 stroke-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="{{ \App\AdminNavigation::icon('help') }}" /></svg></button>
                    <div class="relative shrink-0">
                        <button type="button" class="inline-flex size-10 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-800 ring-1 ring-brand-600/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" x-on:click="menu = !menu" x-bind:aria-expanded="menu" aria-haspopup="menu" aria-label="Conta de {{ $user?->name }}">{{ $initials }}</button>
                        <div x-cloak x-show="menu" x-on:click.outside="menu = false" x-transition.opacity role="menu" aria-label="Conta" class="absolute right-0 z-30 mt-1 grid w-60 gap-0.5 rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl">
                            <p class="truncate px-3 py-1.5 text-xs text-slate-500">{{ $user?->email }}</p>
                            <a role="menuitem" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('consulta.inicio') }}">App do paciente</a>
                            <a role="menuitem" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('account') }}">Minha conta</a>
                            <form method="POST" action="{{ route('logout') }}">@csrf<button role="menuitem" class="w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" type="submit">Sair</button></form>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        {{-- `content-start`: sem isso a grade distribui a altura sobrando da tela entre as linhas e abre espaços em branco. --}}
        <main id="conteudo" class="mx-auto grid w-full min-w-0 max-w-none flex-1 content-start gap-4 px-3 py-4 sm:px-6 xl:px-8 2xl:px-10">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div class="min-w-0">
                    @if ($back)<a class="-ml-1 mb-1 inline-flex items-center gap-1 rounded px-1 text-xs font-medium text-brand-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ $back }}" wire:navigate>← {{ $backLabel }}</a>@endif
                    <h1 class="break-words text-2xl font-semibold leading-tight text-brand-900 sm:text-3xl">{{ $title }}</h1>
                    @if ($subtitle)<p class="mt-1 max-w-4xl text-sm leading-relaxed text-slate-700 sm:text-base">{{ $subtitle }}</p>@endif
                </div>
                @isset($actions)<div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
            </div>
            @if (session('accountFeedback'))<x-knowledge-admin.alert tone="success">{{ session('accountFeedback') }}</x-knowledge-admin.alert>@endif
            {{ $slot }}
        </main>
    </div>
    <x-knowledge-admin.toast />
</div>
