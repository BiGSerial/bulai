<div class="min-h-screen" x-data="{ filtersOpen: true }">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-5 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
            <div>
                <a class="inline-flex" href="{{ route('admin.knowledge.dashboard') }}" wire:navigate aria-label="BULAI — início do Knowledge Admin"><img class="h-12 w-auto sm:h-14" src="/brand/bulai-logo-horizontal-transparente.svg" alt="BULAI — Bulas Inteligentes"></a>
                <h1 class="mt-1 text-2xl font-semibold tracking-normal text-slate-950">Documentos</h1>
            </div>

            <form class="flex items-center gap-3" method="POST" action="{{ route('logout') }}">
                @csrf
                <span class="hidden text-sm text-slate-500 sm:inline">{{ auth()->user()->email }}</span>
                <button class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-50" type="submit">
                    Sair
                </button>
            </form>
        </div>
        <nav aria-label="Knowledge Admin" class="mx-auto flex max-w-7xl gap-1 overflow-x-auto px-4 pb-3 sm:px-6 lg:px-8">
            <a class="whitespace-nowrap rounded-md px-3 py-2 text-sm font-semibold text-slate-600" href="{{ route('admin.knowledge.dashboard') }}" wire:navigate>Visão geral</a>
            <a class="whitespace-nowrap rounded-md bg-teal-50 px-3 py-2 text-sm font-semibold text-teal-800" href="{{ route('admin.knowledge.documents.index') }}" wire:navigate>Documentos</a>
            <a class="whitespace-nowrap rounded-md px-3 py-2 text-sm font-semibold text-slate-600" href="{{ route('admin.knowledge.batches.index') }}" wire:navigate>Importações</a>
            <a class="whitespace-nowrap rounded-md px-3 py-2 text-sm font-semibold text-slate-600" href="{{ route('admin.knowledge.pending.index') }}" wire:navigate>Pendências</a>
        </nav>
    </header>

    <main class="mx-auto grid max-w-7xl gap-5 px-4 py-6 sm:px-6 lg:px-8">
        <section class="grid gap-3">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <label class="grid gap-1 text-sm font-semibold text-slate-700 sm:w-96">
                    Buscar
                    <input
                        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-base font-normal text-slate-950 shadow-sm outline-none transition focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Título, chave externa ou tipo"
                    >
                </label>

                <div class="flex items-center gap-2">
                    <a class="rounded-md border border-teal-700 bg-white px-3 py-2 text-sm font-semibold text-teal-800" href="{{ route('admin.knowledge.documents.create') }}" wire:navigate>Novo documento</a>
                    <button
                        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-50"
                        type="button"
                        x-on:click="filtersOpen = ! filtersOpen"
                    >
                        Filtros
                    </button>
                    <button
                        class="rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-800 disabled:cursor-not-allowed disabled:bg-slate-300"
                        type="button"
                        wire:click="clearFilters"
                        wire:loading.attr="disabled"
                    >
                        Limpar
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap gap-2" x-show="filtersOpen" x-cloak>
                <button
                    class="rounded-full border px-3 py-1.5 text-sm font-semibold {{ $fileStatus === '' ? 'border-teal-700 bg-teal-50 text-teal-800' : 'border-slate-300 bg-white text-slate-700' }}"
                    type="button"
                    wire:click="$set('fileStatus', '')"
                >
                    Todos
                </button>
                @foreach ($fileStatuses as $status)
                    <button
                        class="rounded-full border px-3 py-1.5 text-sm font-semibold {{ $fileStatus === $status ? 'border-teal-700 bg-teal-50 text-teal-800' : 'border-slate-300 bg-white text-slate-700' }}"
                        type="button"
                        wire:click="$set('fileStatus', '{{ $status }}')"
                    >
                        {{ $status }}
                    </button>
                @endforeach
            </div>
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-slate-600">Documento</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-slate-600">Fonte</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-slate-600">Tipo</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-slate-600">Estados</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-slate-600">Chunks</th>
                            <th class="px-4 py-3 text-left text-xs font-bold uppercase text-slate-600">Hash</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($documents as $document)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-4 align-top">
                                    <a class="font-semibold text-teal-700 hover:text-teal-900" href="{{ route('admin.knowledge.documents.show', $document) }}" wire:navigate>
                                        {{ $document->title }}
                                    </a>
                                    <div class="mt-1 text-sm text-slate-500">{{ $document->external_key }}</div>
                                </td>
                                <td class="px-4 py-4 align-top text-sm text-slate-700">{{ $document->source->name }}</td>
                                <td class="px-4 py-4 align-top text-sm text-slate-700">{{ $document->document_type }} / {{ $document->language }}</td>
                                <td class="px-4 py-4 align-top">
                                    <div class="flex flex-wrap gap-1.5">
                                        <span class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-800">{{ $document->content_status }}</span>
                                        <span class="rounded-full bg-cyan-50 px-2 py-1 text-xs font-bold text-cyan-800">{{ $document->file_status }}</span>
                                        <span class="rounded-full bg-amber-50 px-2 py-1 text-xs font-bold text-amber-800">{{ $document->embedding_status }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-4 align-top text-sm font-semibold text-slate-800">{{ $document->chunks_count }}</td>
                                <td class="px-4 py-4 align-top text-sm text-slate-500">{{ $document->sha256 ? str($document->sha256)->limit(16, '') : 'sem PDF' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="px-4 py-8 text-sm text-slate-500" colspan="6">Nenhum documento encontrado.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-200 px-4 py-3">
                {{ $documents->links() }}
            </div>
        </section>
    </main>
</div>
