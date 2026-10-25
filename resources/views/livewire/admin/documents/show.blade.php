<x-knowledge-admin.shell :title="$document->title" :subtitle="$document->external_key" :back="route('admin.knowledge.documents.index')" back-label="Documentos">
    @if ($document->storage_key || $document->storage_path)
        <x-slot:actions>
            <x-knowledge-admin.confirm-modal
                :action="$document->content_status === 'READY' ? 'processDocument(true)' : 'processDocument(false)'"
                :tone="$document->content_status === 'READY' ? 'danger' : 'primary'"
                :title="$document->content_status === 'READY' ? 'Reprocessar documento?' : 'Processar documento?'"
                :message="$document->content_status === 'READY' ? 'Os trechos atuais deste documento serão substituídos pelos do novo processamento, que roda em segundo plano.' : 'O texto do PDF será extraído e dividido em trechos. O processamento roda em segundo plano.'"
                :confirm-label="$document->content_status === 'READY' ? 'Reprocessar' : 'Processar documento'"
                loading="Enfileirando…">
                <x-knowledge-admin.button>{{ $document->content_status === 'READY' ? 'Reprocessar' : 'Processar documento' }}</x-knowledge-admin.button>
            </x-knowledge-admin.confirm-modal>
        </x-slot:actions>
    @endif

    @if ($processingFeedback)<x-knowledge-admin.alert>{{ $processingFeedback }}</x-knowledge-admin.alert>@endif
    @if (($processing['error_code'] ?? null) !== null)
        <x-knowledge-admin.alert tone="danger">Falha no processamento: {{ $processing['error_code'] }}@if (($processing['error_id'] ?? null) !== null) · Código de referência: {{ $processing['error_id'] }}@endif</x-knowledge-admin.alert>
    @endif

    <x-knowledge-admin.section title="Situação"><x-knowledge-admin.document-processing-states :document="$document" /></x-knowledge-admin.section>

    <x-knowledge-admin.section title="Detalhes">
        <x-knowledge-admin.facts class="lg:grid-cols-3">
            <x-knowledge-admin.fact label="Origem">{{ $document->source->name }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Tipo">{{ $document->documentTypeLabel() }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Arquivo">{{ $document->original_filename ?? 'Sem PDF' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Importação">{{ $document->ingestionBatch?->name ?? 'Sem importação' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Processado em">{{ $document->processed_at?->format('d/m/Y H:i') ?? 'Pendente' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Trechos">{{ $document->content_status === 'READY' ? $document->chunks_count : 'Ainda não gerados' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact class="sm:col-span-2 lg:col-span-3" label="Assinatura do arquivo (SHA-256)"><span class="font-mono text-xs">{{ $document->sha256 ?? 'Sem PDF' }}</span></x-knowledge-admin.fact>
        </x-knowledge-admin.facts>
    </x-knowledge-admin.section>

    <section class="grid gap-3">
        <h2 class="text-base font-semibold text-slate-900">Trechos ({{ $document->chunks_count }})</h2>
        @forelse ($chunks as $chunk)
            <x-knowledge-admin.card>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-semibold text-slate-900">#{{ $chunk->chunk_index }}</span>
                    <span class="text-sm text-slate-500">{{ $chunk->section ?? 'Sem seção' }}</span>
                    @if ($chunk->page_start || $chunk->page_end)<x-knowledge-admin.badge>páginas {{ $chunk->page_start ?? '?' }}-{{ $chunk->page_end ?? '?' }}</x-knowledge-admin.badge>@endif
                </div>
                <pre class="mt-3 whitespace-pre-wrap break-words font-sans text-sm text-slate-800">{{ $chunk->content }}</pre>
            </x-knowledge-admin.card>
        @empty
            <x-knowledge-admin.empty-state>Nenhum trecho registrado.</x-knowledge-admin.empty-state>
        @endforelse
        {{ $chunks->links() }}
    </section>
</x-knowledge-admin.shell>
