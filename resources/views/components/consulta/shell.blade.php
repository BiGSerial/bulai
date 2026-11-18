@props(['title' => null, 'back' => null, 'conta' => false, 'cuidando' => null])
@php
    // Quatro destinos na barra inferior, como no aplicativo Android: Início, Perguntar, Remédios e Conta. Os avisos ficam no sininho.
    $items = [
        ['route' => 'consulta.inicio', 'label' => 'Início', 'pattern' => ['consulta.inicio', 'consulta.avisos'], 'icon' => 'm2.25 12 8.954-8.955a1.126 1.126 0 0 1 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75'],
        ['route' => 'consulta.perguntar', 'label' => 'Perguntar', 'pattern' => ['consulta.perguntar', 'consulta.pergunta', 'consulta.historico', 'consulta.bula', 'consulta.bulas'], 'icon' => 'M8.625 9.75a3.375 3.375 0 1 1 6.75 0c0 1.5-1.125 2.25-2.25 3-.75.5-1.125 1-1.125 1.875M12 18h.008M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'],
        ['route' => 'consulta.hoje', 'label' => 'Remédios', 'pattern' => ['consulta.hoje', 'consulta.perfil', 'consulta.receita.*', 'consulta.pesquisa', 'consulta.resultado'], 'icon' => 'M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z'],
        ['route' => 'consulta.conta', 'label' => 'Conta', 'pattern' => ['consulta.conta', 'consulta.cuidadores', 'consulta.cuidar', 'consulta.sobre'], 'icon' => 'M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z'],
    ];
    $active = fn (array $item): bool => request()->routeIs(...(array) $item['pattern']);
    $user = auth()->user();
    $nomeCuidado = $cuidando?->patient?->name;
@endphp
{{--
    Casca do app do paciente. `app-screen` (resources/css/app.css) dá altura real da janela, corta excesso horizontal,
    resposta tátil nos botões e respeito às áreas seguras quando instalado. Menus fecham ao navegar (`bulai-navegando`).
--}}
<div class="app-screen min-h-screen bg-surface-50 pb-28 text-lg leading-relaxed text-slate-900 sm:pb-12"
     x-data="{ offline: !navigator.onLine, update: false, menu: false, aviso: false, instalar: false }"
     x-init="try { aviso = localStorage.getItem('bulai-aviso-visto') !== '1' } catch (e) { aviso = false }; if (window.bulaiInstalacao) { const s = window.bulaiInstalacao(); instalar = !s.installed && (s.prompt || s.ios) }"
     x-on:online.window="offline = false" x-on:offline.window="offline = true" x-on:bulai-sw-update.window="update = true"
     x-on:bulai-pode-instalar.window="instalar = !$event.detail.installed && ($event.detail.prompt || $event.detail.ios)"
     x-on:bulai-navegando.window="menu = false" x-on:keydown.escape.window="menu = false">
    <a class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-2 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2 focus:font-medium focus:text-brand-800 focus:shadow" href="#conteudo">Ir para o conteúdo</a>

    <div x-cloak x-show="offline" role="status" class="bg-amber-100 px-4 py-3 text-center font-medium text-amber-900">Sem internet. O aplicativo precisa de conexão.</div>
    <div x-cloak x-show="update" role="status" class="flex flex-wrap items-center justify-center gap-3 bg-brand-700 px-4 py-3 text-white">
        Há uma versão nova.
        <button type="button" class="min-h-12 rounded-lg bg-white px-4 font-semibold text-brand-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-white" x-on:click="window.bulaiApplyUpdate()">Atualizar</button>
    </div>

    <header class="app-header sticky top-0 z-20 border-b border-brand-100 bg-white/95 backdrop-blur">
        <div class="mx-auto flex max-w-3xl items-center gap-1 px-2 py-2 sm:px-4">
            @if ($back)
                <a class="inline-flex min-h-14 min-w-14 shrink-0 items-center justify-center gap-1 rounded-xl px-2 text-brand-800 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ $back }}" wire:navigate aria-label="Voltar"><svg class="size-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg><span class="hidden font-semibold sm:inline">Voltar</span></a>
            @else
                <a class="inline-flex size-14 shrink-0 items-center justify-center rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('consulta.inicio') }}" wire:navigate aria-label="BULA-IA — início"><img class="size-10" src="/brand/bulai-icone-app.svg" alt="" width="40" height="40"></a>
            @endif
            <h1 class="line-clamp-2 min-w-0 flex-1 break-words text-2xl font-semibold leading-tight text-brand-900 sm:text-3xl">{{ $title ?? 'BULA-IA' }}</h1>
            <nav class="hidden items-center gap-1 sm:flex" aria-label="Principal">
                @foreach ($items as $item)
                    <a href="{{ route($item['route']) }}" wire:navigate @if ($active($item)) aria-current="page" @endif class="relative min-h-12 rounded-lg px-3 py-2 font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 {{ $active($item) ? 'bg-brand-50 text-brand-900 after:absolute after:inset-x-3 after:-bottom-0.5 after:h-0.5 after:rounded-full after:bg-accent-500' : 'text-slate-700 hover:bg-brand-50 hover:text-brand-900' }}">{{ $item['label'] }}</a>
                @endforeach
            </nav>
            {{-- Sino de avisos: o único acesso aos avisos, com a contagem de não lidos (como no aplicativo Android). A conta virou o 4º destino da barra. --}}
            <livewire:consulta.sino-de-avisos />
        </div>
        @if ($nomeCuidado !== null)
            {{-- Modo cuidador: sempre visível (fica presa ao topo com o cabeçalho) de quem são os dados desta tela. --}}
            <div class="border-t-2 border-accent-300 bg-accent-50" role="status" data-cuidando="{{ $cuidando->id }}">
                <div class="mx-auto flex max-w-3xl flex-wrap items-center gap-2 px-3 py-2 sm:px-4">
                    <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-accent-600 text-lg font-bold text-white" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($nomeCuidado), 0, 1)) }}</span>
                    <p class="min-w-0 flex-1 break-words text-lg font-semibold leading-snug text-accent-900">Você está vendo os remédios de {{ $nomeCuidado }}</p>
                    <a class="inline-flex min-h-12 shrink-0 items-center rounded-xl bg-white px-3 text-base font-semibold text-brand-800 ring-1 ring-brand-600/30 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('consulta.hoje') }}" wire:navigate>Voltar para os meus</a>
                </div>
            </div>
        @endif
    </header>

    <x-consulta.toast />

    <main id="conteudo" class="mx-auto grid min-w-0 max-w-3xl gap-4 px-3 py-4 sm:px-4 {{ isset($sticky) ? 'pb-28 sm:pb-6' : '' }}">
        @if (session('accountFeedback'))<x-knowledge-admin.alert tone="success">{{ session('accountFeedback') }}</x-knowledge-admin.alert>@endif
        {{-- Aviso completo na primeira abertura; depois fica em "Saiba mais". --}}
        <section x-cloak x-show="aviso" class="grid gap-3 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-amber-900" aria-labelledby="aviso-titulo">
            <h2 id="aviso-titulo" class="text-xl font-semibold">Antes de usar</h2>
            <p>{{ config('bulai.guidelines.disclaimer') }}</p>
            <button type="button" class="min-h-14 rounded-xl bg-brand-700 px-4 text-lg font-semibold text-white hover:bg-brand-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2" x-on:click="aviso = false; try { localStorage.setItem('bulai-aviso-visto', '1') } catch (e) {}">Entendi</button>
        </section>
        {{ $slot }}
        <p class="px-1 text-base text-slate-700" role="note">O BULA-IA não substitui seu médico ou farmacêutico. <a class="font-semibold text-brand-800 underline" href="{{ route('consulta.sobre') }}" wire:navigate>Saiba mais</a></p>
    </main>

    @isset($sticky)
        {{-- Ação principal da tela, sempre ao alcance do polegar (acima da barra inferior no celular). --}}
        <div class="fixed inset-x-0 bottom-[calc(4.75rem+env(safe-area-inset-bottom,0px))] z-20 border-t border-brand-100 bg-white/95 px-3 py-1.5 backdrop-blur sm:bottom-0 sm:border-t-0 sm:bg-transparent sm:py-3">
            <div class="mx-auto max-w-3xl">{{ $sticky }}</div>
        </div>
    @endisset

    {{-- Barra inferior fixa (4 destinos): grid-cols-4 é a exceção registrada em ConsultaDesignSystemTest. --}}
    <nav class="fixed inset-x-0 bottom-0 z-20 border-t border-brand-100 bg-white pb-[env(safe-area-inset-bottom,0px)] sm:hidden" aria-label="Principal (celular)">
        <ul class="mx-auto grid max-w-3xl grid-cols-4">
            @foreach ($items as $item)
                <li><a href="{{ route($item['route']) }}" wire:navigate @if ($active($item)) aria-current="page" @endif class="relative flex min-h-[4.75rem] flex-col items-center justify-center gap-0.5 text-base font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-600 {{ $active($item) ? 'text-brand-800 before:absolute before:inset-x-6 before:top-0 before:h-1 before:rounded-b-full before:bg-accent-500' : 'text-slate-600' }}"><span class="rounded-full px-3 py-0.5 transition-colors {{ $active($item) ? 'bg-brand-50' : '' }}"><svg class="size-7" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" /></svg></span><span class="truncate">{{ $item['label'] }}</span></a></li>
            @endforeach
        </ul>
    </nav>
</div>
