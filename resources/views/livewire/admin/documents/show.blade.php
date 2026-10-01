<div class="min-h-screen">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto grid max-w-7xl gap-3 px-4 py-5 sm:px-6 lg:px-8">
            <a class="text-sm font-semibold text-teal-700 hover:text-teal-900" href="{{ route('admin.knowledge.documents.index') }}" wire:navigate>
                Voltar
            </a>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm text-slate-500">{{ $document->external_key }}</p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-normal text-slate-950 sm:text-3xl">{{ $document->title }}</h1>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <span class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-800">{{ $document->content_status }}</span>
                    <span class="rounded-full bg-cyan-50 px-2 py-1 text-xs font-bold text-cyan-800">{{ $document->file_status }}</span>
                    <span class="rounded-full bg-amber-50 px-2 py-1 text-xs font-bold text-amber-800">{{ $document->embedding_status }}</span>
                </div>
            </div>
        </div>
    </header>

    <main class="mx-auto grid max-w-7xl gap-6 px-4 py-6 sm:px-6 lg:px-8">
        @if ($processingFeedback)
            <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" role="status">{{ $processingFeedback }}</div>
        @endif
        <section class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-xs font-bold uppercase text-slate-500">Fonte</dt>
                <dd class="mt-1 text-sm text-slate-950">{{ $document->source->name }}</dd>
            </div>
            <div>
                <dt class="text-xs font-bold uppercase text-slate-500">Tipo</dt>
                <dd class="mt-1 text-sm text-slate-950">{{ $document->document_type }} / {{ $document->language }}</dd>
            </div>
            <div>
                <dt class="text-xs font-bold uppercase text-slate-500">Arquivo</dt>
                <dd class="mt-1 break-words text-sm text-slate-950">{{ $document->original_filename ?? 'sem PDF' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-bold uppercase text-slate-500">Processado em</dt>
                <dd class="mt-1 text-sm text-slate-950">{{ $document->processed_at?->toDateTimeString() ?? 'pendente' }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-xs font-bold uppercase text-slate-500">SHA-256</dt>
                <dd class="mt-1 break-all text-sm text-slate-950">{{ $document->sha256 ?? 'sem hash' }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-xs font-bold uppercase text-slate-500">Batch</dt>
                <dd class="mt-1 text-sm text-slate-950">{{ $document->ingestionBatch?->name ?? 'sem batch' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-bold uppercase text-slate-500">Chunks</dt>
                <dd class="mt-1 text-sm text-slate-950">{{ $document->chunks_count }}</dd>
            </div>
            @if (($processing['error_code'] ?? null) !== null)
                <div>
                    <dt class="text-xs font-bold uppercase text-slate-500">Falha de conteúdo</dt>
                    <dd class="mt-1 text-sm text-rose-800">{{ $processing['error_code'] }}</dd>
                    @if (($processing['error_id'] ?? null) !== null)
                        <dd class="mt-1 text-xs text-slate-500">Referência: {{ $processing['error_id'] }}</dd>
                    @endif
                </div>
            @endif
            <div class="flex items-end sm:col-span-2">
                @if ($document->storage_key || $document->storage_path)
                    <button
                        class="rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:cursor-wait disabled:bg-slate-400"
                        type="button"
                        wire:click="processDocument({{ $document->content_status === 'READY' ? 'true' : 'false' }})"
                        wire:confirm="{{ $document->content_status === 'READY' ? 'Reprocessar e substituir os chunks atuais?' : 'Iniciar o processamento deste documento?' }}"
                        wire:loading.attr="disabled"
                        wire:target="processDocument"
                    >
                        <span wire:loading.remove wire:target="processDocument">{{ $document->content_status === 'READY' ? 'Reprocessar' : 'Processar documento' }}</span>
                        <span wire:loading wire:target="processDocument">Enfileirando…</span>
                    </button>
                @endif
            </div>
        </section>

        <section class="grid gap-3">
            <h2 class="text-lg font-semibold tracking-normal text-slate-950">Chunks ({{ $document->chunks_count }})</h2>

            @forelse ($chunks as $chunk)
                <article class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <strong class="text-sm font-bold text-slate-950">#{{ $chunk->chunk_index }}</strong>
                        <span class="text-sm text-slate-500">{{ $chunk->section ?? 'sem seção' }}</span>
                        @if ($chunk->page_start || $chunk->page_end)
                            <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">
                                paginas {{ $chunk->page_start ?? '?' }}-{{ $chunk->page_end ?? '?' }}
                            </span>
                        @endif
                    </div>
                    <pre class="mt-3 whitespace-pre-wrap break-words font-sans text-sm leading-6 text-slate-800">{{ $chunk->content }}</pre>
                </article>
            @empty
                <p class="text-sm text-slate-500">Nenhum chunk registrado.</p>
            @endforelse
            {{ $chunks->links() }}
        </section>
    </main>
</div>
