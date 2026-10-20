@props(['title', 'subtitle' => null, 'back' => null, 'backLabel' => 'Voltar'])
@php
    $pendingCount = \App\Models\Knowledge\IngestionBatchItem::query()->where('status', \App\Models\Knowledge\IngestionBatchItem::STATUS_PENDING)->count();
    $icons = [
        'home' => 'm2.25 12 8.954-8.955a1.126 1.126 0 0 1 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
        'plus' => 'M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'document' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
        'inbox' => 'M9 8.25H7.5a2.25 2.25 0 0 0-2.25 2.25v9a2.25 2.25 0 0 0 2.25 2.25h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25H15M9 12l3 3m0 0 3-3m-3 3V2.25',
        'alert' => 'M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z',
        'library' => 'M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z',
        'beaker' => 'M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23-.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5',
    ];
    // [rota, rótulo, padrão de rota ativa, ícone, explicação (dica ao passar o mouse)]
    $navigation = [
        ['admin.knowledge.dashboard', 'Visão geral', 'admin.knowledge.dashboard', 'home', 'Resumo do que está na base e do que precisa de atenção'],
        ['admin.knowledge.upload', 'Adicionar documentos', 'admin.knowledge.upload', 'plus', 'Enviar PDFs de bulas para a base'],
        ['admin.knowledge.batches.index', 'Importações', 'admin.knowledge.batches.*', 'inbox', 'Acompanhar cada envio e o que aconteceu com os arquivos'],
        ['admin.knowledge.pending.index', 'Pendências', 'admin.knowledge.pending.*', 'alert', 'Arquivos que precisam da sua decisão antes de entrar na base'],
        ['admin.knowledge.documents.index', 'Documentos', 'admin.*documents.*', 'document', 'Documentos já incorporados à base'],
        ['admin.knowledge.sources.index', 'Origens', 'admin.knowledge.sources.*', 'library', 'De onde vêm os documentos (ex.: ANVISA)'],
        ['admin.knowledge.catalog.index', 'Catálogo ANVISA', 'admin.knowledge.catalog.*', 'beaker', 'Lista oficial de medicamentos usada para validar bulas'],
    ];
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
                <x-knowledge-admin.button variant="secondary" type="submit">Sair</x-knowledge-admin.button>
            </form>
        </div>
        <nav class="mx-auto flex max-w-7xl gap-1 overflow-x-auto px-4 pb-2 sm:px-6 lg:px-8" aria-label="Navegação principal">
            @foreach ($navigation as [$routeName, $label, $pattern, $icon, $hint])
                <a class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs($pattern) ? 'bg-brand-50 text-brand-800' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}" href="{{ route($routeName) }}" wire:navigate title="{{ $hint }}" @if (request()->routeIs($pattern)) aria-current="page" @endif><svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="{{ $icons[$icon] }}" /></svg>{{ $label }}@if ($routeName === 'admin.knowledge.pending.index' && $pendingCount > 0)<x-knowledge-admin.badge tone="warning">{{ $pendingCount }}</x-knowledge-admin.badge>@endif</a>
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
        {{ $slot }}
    </main>
</div>
