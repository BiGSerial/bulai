@props(['title', 'subtitle' => null, 'back' => null, 'backLabel' => 'Voltar'])
@php
    $pendingCount = \App\Models\Knowledge\IngestionBatchItem::query()->where('status', \App\Models\Knowledge\IngestionBatchItem::STATUS_PENDING)->count();
    // Itens do menu (rota, rótulo, padrão de rota ativa, ícone e dica): fonte única em App\AdminNavigation.
    $navigation = \App\AdminNavigation::items();
@endphp
<div class="min-h-screen bg-slate-50 text-slate-900">
    <a class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-2 focus:z-30 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2 focus:text-sm focus:font-medium focus:text-brand-800 focus:shadow-sm" href="#conteudo">Ir para o conteúdo</a>
    <header class="border-b border-slate-200 bg-white shadow-sm">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3 sm:px-6 lg:px-8">
            <a class="inline-flex" href="{{ route('admin.knowledge.dashboard') }}" wire:navigate aria-label="BULAI — página inicial"><img class="h-9 w-auto" src="/brand/bulai-logo-horizontal-transparente.svg" alt="BULAI — Bulas Inteligentes"></a>
            <div class="order-last w-full sm:order-none sm:ml-auto sm:w-auto"><livewire:admin.knowledge.service-health /></div>
            <form class="flex items-center gap-3" method="POST" action="{{ route('logout') }}">
                @csrf
                <span class="hidden text-sm text-slate-500 sm:inline">{{ auth()->user()->email }}</span>
                <a class="rounded-lg px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100 hover:text-slate-900" href="{{ route('account') }}">Minha conta</a>
                <x-knowledge-admin.button variant="secondary" type="submit">Sair</x-knowledge-admin.button>
            </form>
        </div>
        <nav class="mx-auto flex max-w-7xl gap-1 overflow-x-auto px-4 pb-2 sm:px-6 lg:px-8" aria-label="Navegação principal">
            @foreach ($navigation as ['route' => $routeName, 'label' => $label, 'pattern' => $pattern, 'icon' => $icon, 'hint' => $hint])
                <a class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs($pattern) ? 'bg-brand-50 text-brand-800' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}" href="{{ route($routeName) }}" wire:navigate title="{{ $hint }}" @if (request()->routeIs($pattern)) aria-current="page" @endif><svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="{{ $icon }}" /></svg>{{ $label }}@if ($routeName === 'admin.knowledge.pending.index' && $pendingCount > 0)<x-knowledge-admin.badge tone="warning">{{ $pendingCount }}</x-knowledge-admin.badge>@endif</a>
            @endforeach
        </nav>
    </header>
    <main id="conteudo" class="mx-auto grid max-w-7xl gap-5 px-4 py-6 sm:px-6 lg:px-8">
        @if ($back)<div><a class="inline-flex items-center gap-1 rounded-lg px-2 py-1 -ml-2 text-sm font-medium text-brand-700 transition hover:bg-brand-50 hover:text-brand-800" href="{{ $back }}" wire:navigate>← {{ $backLabel }}</a></div>@endif
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold text-slate-900">{{ $title }}</h1>
                @if ($subtitle)<p class="mt-1 text-sm text-slate-600">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)<div class="flex flex-wrap gap-2">{{ $actions }}</div>@endisset
        </div>
        @if (session('accountFeedback'))<x-knowledge-admin.alert tone="success">{{ session('accountFeedback') }}</x-knowledge-admin.alert>@endif
        {{ $slot }}
    </main>
</div>
