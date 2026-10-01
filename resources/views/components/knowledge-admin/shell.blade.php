@props(['title'])
<div class="min-h-screen bg-slate-50">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
            <div class="grid gap-3"><a class="inline-flex w-fit" href="{{ route('admin.knowledge.dashboard') }}" wire:navigate aria-label="BULAI — início do Knowledge Admin"><img class="h-12 w-auto sm:h-14" src="/brand/bulai-logo-horizontal-transparente.svg" alt="BULAI — Bulas Inteligentes"></a><h1 class="text-2xl font-semibold text-slate-950">{{ $title }}</h1></div>
            <form class="flex items-center gap-3" method="POST" action="{{ route('logout') }}">@csrf<span class="hidden text-sm text-slate-500 sm:inline">{{ auth()->user()->email }}</span><button class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50" type="submit">Sair</button></form>
        </div>
        <nav aria-label="Knowledge Admin" class="mx-auto flex max-w-7xl gap-1 overflow-x-auto px-4 pb-3 sm:px-6 lg:px-8">
            @foreach ([['admin.knowledge.dashboard', 'Visão geral'], ['admin.knowledge.upload', 'Adicionar documentos'], ['admin.knowledge.documents.index', 'Documentos'], ['admin.knowledge.batches.index', 'Importações'], ['admin.knowledge.pending.index', 'Pendências'], ['admin.knowledge.sources.index', 'Origens']] as [$routeName, $label])
                <a class="whitespace-nowrap rounded-md px-3 py-2 text-sm font-semibold {{ request()->routeIs($routeName) ? 'bg-teal-50 text-teal-800' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950' }}" href="{{ route($routeName) }}" wire:navigate>{{ $label }}</a>
            @endforeach
        </nav>
    </header>
    <main class="mx-auto grid max-w-7xl gap-5 px-4 py-6 sm:px-6 lg:px-8">{{ $slot }}</main>
</div>
