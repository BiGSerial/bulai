@props(['title', 'subtitle' => null, 'back' => null, 'backLabel' => 'Voltar'])
@php
    $pendingCount = \App\Models\Knowledge\IngestionBatchItem::query()->where('status', \App\Models\Knowledge\IngestionBatchItem::STATUS_PENDING)->count();
    $navigation = [
        ['admin.knowledge.dashboard', 'Visão geral', 'admin.knowledge.dashboard'],
        ['admin.knowledge.upload', 'Adicionar documentos', 'admin.knowledge.upload'],
        ['admin.knowledge.documents.index', 'Documentos', 'admin.*documents.*'],
        ['admin.knowledge.batches.index', 'Importações', 'admin.knowledge.batches.*'],
        ['admin.knowledge.pending.index', 'Pendências', 'admin.knowledge.pending.*'],
        ['admin.knowledge.sources.index', 'Origens', 'admin.knowledge.sources.*'],
    ];
@endphp
<div class="min-h-screen bg-slate-50 text-slate-900">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
            <a class="inline-flex" href="{{ route('admin.knowledge.dashboard') }}" wire:navigate aria-label="BULAI — página inicial"><img class="h-9 w-auto" src="/brand/bulai-logo-horizontal-transparente.svg" alt="BULAI — Bulas Inteligentes"></a>
            <form class="flex items-center gap-3" method="POST" action="{{ route('logout') }}">
                @csrf
                <span class="hidden text-sm text-slate-500 sm:inline">{{ auth()->user()->email }}</span>
                <x-knowledge-admin.button variant="secondary" type="submit">Sair</x-knowledge-admin.button>
            </form>
        </div>
        <nav class="mx-auto flex max-w-7xl gap-1 overflow-x-auto px-4 pb-2 sm:px-6 lg:px-8" aria-label="Navegação principal">
            @foreach ($navigation as [$routeName, $label, $pattern])
                <a class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium {{ request()->routeIs($pattern) ? 'bg-brand-50 text-brand-800' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}" href="{{ route($routeName) }}" wire:navigate @if (request()->routeIs($pattern)) aria-current="page" @endif>{{ $label }}@if ($routeName === 'admin.knowledge.pending.index' && $pendingCount > 0)<x-knowledge-admin.badge tone="warning">{{ $pendingCount }}</x-knowledge-admin.badge>@endif</a>
            @endforeach
        </nav>
    </header>
    <main class="mx-auto grid max-w-7xl gap-5 px-4 py-6 sm:px-6 lg:px-8">
        @if ($back)<div><a class="text-sm font-medium text-brand-700 hover:text-brand-800" href="{{ $back }}" wire:navigate>← {{ $backLabel }}</a></div>@endif
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
