<x-knowledge-admin.shell :title="$item->original_filename" :subtitle="($item->ingestionBatch->source?->name ?? 'Sem origem').' · '.$item->created_at->format('d/m/Y H:i')" :back="route('admin.knowledge.batches.show', $batch)" back-label="Importação">
    <x-slot:actions>
        <x-knowledge-admin.status-badge :status="$item->status" />
        <x-knowledge-admin.button variant="secondary" target="_blank" :href="route('admin.knowledge.items.file', [$batch, $item])">Visualizar PDF</x-knowledge-admin.button>
        <x-knowledge-admin.button variant="secondary" :href="route('admin.knowledge.items.download', [$batch, $item])" download>Baixar original</x-knowledge-admin.button>
    </x-slot:actions>

    @if ($feedbackMessage)
        <x-knowledge-admin.alert :tone="match ($feedbackType) { 'success' => 'success', 'error' => 'danger', default => 'warning' }">{{ $feedbackMessage }}@if ($errorId)<span class="mt-1 block">Código de referência: {{ $errorId }}</span>@endif</x-knowledge-admin.alert>
    @endif

    <x-knowledge-admin.section title="Por que está pendente">
        <p class="text-sm text-slate-800">{{ match ($item->reason) { 'no_match_found' => 'Nenhum documento correspondente foi encontrado.', 'ambiguous_source_document_id', 'ambiguous_normalized_filename' => 'Mais de um documento é possível. Confirme qual é o correto.', default => $item->reason ?: 'Associação manual necessária.' } }}</p>
        @if ($identification)
            <div class="grid gap-3 border-t border-slate-100 pt-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div><h3 class="text-sm font-semibold text-slate-900">Dados identificados no PDF</h3><p class="text-sm text-slate-500">Confira antes de usar: são sugestões, ainda não confirmadas.</p></div>
                    @if ($identificationSummary)<x-knowledge-admin.badge>Confiança {{ $identificationSummary['score'] }}/100</x-knowledge-admin.badge>@endif
                </div>
                <x-knowledge-admin.facts>
                    @foreach (['product_name' => 'Medicamento', 'active_ingredient' => 'Princípio ativo', 'registration_number' => 'Registro ANVISA', 'document_type' => 'Tipo', 'holder' => 'Titular'] as $field => $label)
                        @if (isset($identification[$field]))<x-knowledge-admin.fact :label="$label">{{ $identification[$field] }}</x-knowledge-admin.fact>@endif
                    @endforeach
                </x-knowledge-admin.facts>
                @if ($identificationSummary && $identificationSummary['ambiguous'])<x-knowledge-admin.alert tone="warning">Campos ambíguos: {{ implode(', ', $identificationSummary['ambiguous']) }}.</x-knowledge-admin.alert>@endif
            </div>
        @endif
    </x-knowledge-admin.section>

    @if ($item->status === \App\Models\Knowledge\IngestionBatchItem::STATUS_PENDING)
        <x-knowledge-admin.section title="Resolver pendência" description="Associe o arquivo a um documento existente ou crie um novo documento.">
            <x-slot:actions>
                <x-knowledge-admin.button variant="secondary" wire:click="$toggle('creatingDocument')">+ Novo documento</x-knowledge-admin.button>
                <x-knowledge-admin.button variant="secondary" wire:click="reanalyze" loading-target="reanalyze" loading="Reanalisando…">Reanalisar</x-knowledge-admin.button>
            </x-slot:actions>

            @if ($creatingDocument)
                <div class="grid gap-4 rounded-lg border border-brand-100 bg-brand-50 p-4">
                    <p class="text-sm text-slate-700">A origem e o arquivo serão preservados. Revise os dados antes de confirmar.</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-knowledge-admin.input label="Título" required wire:model="newDocumentTitle" wrapper-class="sm:col-span-2" />
                        <x-knowledge-admin.select label="Tipo" required wire:model="newDocumentType">@foreach (\App\Models\Knowledge\KnowledgeDocument::documentTypeOptions() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-knowledge-admin.select>
                        <x-knowledge-admin.input label="Identificador oficial" optional wire:model="newDocumentSourceId" />
                        <x-knowledge-admin.input label="Chave externa" optional wire:model="newDocumentExternalKey" wrapper-class="sm:col-span-2" />
                    </div>
                    <div><x-knowledge-admin.button wire:click="createDocument" loading-target="createDocument" loading="Criando e associando…">Criar e associar documento</x-knowledge-admin.button></div>
                </div>
            @endif

            <x-knowledge-admin.search label="Buscar documento existente" wire:model.live.debounce.350ms="documentSearch" placeholder="Título, chave ou identificador" />
            <div class="grid gap-2" wire:loading.class="opacity-60" wire:target="documentSearch">
                @foreach ($documents as $document)
                    <button type="button" wire:click="selectDocument({{ $document->id }})" class="rounded-lg border p-3 text-left transition {{ $selectedDocumentId === $document->id ? 'border-brand-600 bg-brand-50 ring-2 ring-brand-600/20' : 'border-slate-200 hover:border-slate-400' }}">
                        <span class="block text-sm font-semibold text-slate-900">{{ $document->title }}</span>
                        <span class="mt-0.5 block text-sm text-slate-500">{{ $document->source?->name ?? 'Sem origem' }} · {{ $document->external_key ?: 'Sem chave externa' }}</span>
                        <span class="mt-1 block text-sm {{ ($document->storage_key || $document->storage_path) ? 'text-rose-700' : 'text-emerald-700' }}">{{ ($document->storage_key || $document->storage_path) ? 'Já possui PDF' : 'Sem PDF associado' }}</span>
                    </button>
                @endforeach
                @if (mb_strlen(trim($documentSearch)) >= 2 && $documents->isEmpty())<x-knowledge-admin.empty-state>Nenhum documento encontrado.</x-knowledge-admin.empty-state>@endif
            </div>
            @error('selectedDocumentId')<p class="text-sm text-rose-700">Selecione um documento válido.</p>@enderror

            @if ($selectedDocument)
                <x-knowledge-admin.alert tone="info">
                    <p class="font-medium">Confirme a associação</p>
                    <p class="mt-1">Arquivo: <strong>{{ $item->original_filename }}</strong></p>
                    <p>Documento selecionado: <strong>{{ $selectedDocument->title }}</strong></p>
                    <x-knowledge-admin.button class="mt-3" wire:click="associate" loading-target="associate" loading="Associando…">Associar documento</x-knowledge-admin.button>
                </x-knowledge-admin.alert>
            @endif
        </x-knowledge-admin.section>

        <x-knowledge-admin.section title="Não incorporar à base" description="Use quando o arquivo não deve entrar na base. O arquivo e o histórico são preservados.">
            <div><x-knowledge-admin.button variant="secondary" wire:click="$toggle('showIgnoreForm')">Não incorporar…</x-knowledge-admin.button></div>
            @if ($showIgnoreForm)
                <div class="grid gap-4">
                    <x-knowledge-admin.select label="Motivo" required wire:model="ignoreReason">
                        <option value="">Selecione…</option><option value="not_leaflet">Documento não é bula</option><option value="wrong_source">Origem incorreta</option><option value="out_of_scope">Fora do escopo</option><option value="invalid_document">Documento inválido</option><option value="duplicate_unresolved">Duplicidade</option><option value="inappropriate_content">Conteúdo inadequado</option><option value="other">Outro</option>
                    </x-knowledge-admin.select>
                    <x-knowledge-admin.textarea label="Observação" optional wire:model="ignoreNotes" />
                    <div><x-knowledge-admin.button variant="danger" wire:click="ignore" loading-target="ignore" loading="Salvando…">Confirmar sem incorporar</x-knowledge-admin.button></div>
                </div>
            @endif
        </x-knowledge-admin.section>
    @else
        <x-knowledge-admin.alert>Este item não está mais pendente e não pode receber uma nova associação.</x-knowledge-admin.alert>
    @endif

    <x-knowledge-admin.section title="Detalhes do arquivo">
        <x-knowledge-admin.facts>
            <x-knowledge-admin.fact label="Identificador externo">{{ $item->external_key ?: 'Não informado' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Recebido em">{{ $item->created_at->format('d/m/Y H:i') }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Canal">{{ data_get($item->metadata, 'ingestion_channel') === 'knowledge_admin' ? 'Envio administrativo' : (data_get($item->metadata, 'ingestion_channel') ?? 'Não informado') }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Tamanho">{{ $item->file_size ? number_format($item->file_size / 1024, 1, ',', '.').' KB' : 'Não informado' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact class="sm:col-span-2" label="Assinatura do arquivo (SHA-256)"><span class="font-mono text-xs">{{ $item->sha256 ?? 'Não informada' }}</span> @if ($item->sha256)<x-knowledge-admin.button variant="quiet" x-data x-on:click="navigator.clipboard.writeText(@js($item->sha256))">Copiar</x-knowledge-admin.button>@endif</x-knowledge-admin.fact>
        </x-knowledge-admin.facts>
        @if ($metadata)
            <div class="border-t border-slate-100 pt-4"><h3 class="mb-3 text-sm font-semibold text-slate-900">Metadados importados</h3>
                <x-knowledge-admin.facts>@foreach ($metadata as $key => $value)<x-knowledge-admin.fact :label="str($key)->headline()">{{ is_bool($value) ? ($value ? 'Sim' : 'Não') : $value }}</x-knowledge-admin.fact>@endforeach</x-knowledge-admin.facts>
            </div>
        @endif
    </x-knowledge-admin.section>

    <x-knowledge-admin.section title="Histórico">
        @forelse ($history as $event)
            <div class="border-l-2 border-slate-200 pl-3">
                <p class="text-sm font-medium text-slate-900">{{ match ($event['event'] ?? '') { 'reanalysis_requested' => 'Reanálise solicitada', 'preliminary_identification_completed' => 'Identificação preliminar concluída', 'reanalysis_remained_pending' => 'Item permaneceu pendente', 'document_associated' => 'Documento associado', 'duplicate_confirmed' => 'Duplicidade confirmada', 'integrity_check_failed' => 'Falha de integridade detectada', 'not_incorporated' => 'Resolvido sem incorporação', default => str($event['event'] ?? 'Evento')->headline() } }}</p>
                <p class="text-sm text-slate-500">{{ isset($event['occurred_at']) ? \Illuminate\Support\Carbon::parse($event['occurred_at'])->format('d/m/Y H:i') : '' }}@if ($event['actor_name'] ?? null) · {{ $event['actor_name'] }}@endif</p>
            </div>
        @empty
            <p class="text-sm text-slate-500">Nenhum evento registrado para este item.</p>
        @endforelse
    </x-knowledge-admin.section>
</x-knowledge-admin.shell>
