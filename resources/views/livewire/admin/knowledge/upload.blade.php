<x-knowledge-admin.shell title="Adicionar documentos" :subtitle="'Selecione a origem e envie PDFs com até '.$maxBatchMegabytes.' MB no total. Os arquivos associados serão processados em segundo plano.'">
    @if ($feedback)
        <x-knowledge-admin.alert :tone="$errorId ? 'danger' : 'success'">
            <p class="font-medium">{{ $feedback }}</p>
            @if ($errorId)<p class="mt-1">Código de referência: {{ $errorId }}</p>@endif
        </x-knowledge-admin.alert>
    @endif

    <form class="max-w-3xl" wire:submit="ingest" x-data="{ uploading: false, progress: 0 }" x-on:livewire-upload-start="uploading = true" x-on:livewire-upload-finish="uploading = false; progress = 100" x-on:livewire-upload-error="uploading = false" x-on:livewire-upload-progress="progress = $event.detail.progress">
        <x-knowledge-admin.section title="Nova importação">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                <x-knowledge-admin.select label="Origem dos documentos" required wire:model="sourceId" hint="Instituição ou base de onde os documentos foram obtidos." wrapper-class="flex-1">
                    <option value="">Selecione a origem dos PDFs…</option>
                    @foreach ($sources as $source)<option value="{{ $source->id }}">{{ $source->displayName() }}</option>@endforeach
                </x-knowledge-admin.select>
                <x-knowledge-admin.button variant="secondary" wire:click="openSourceModal">+ Nova origem</x-knowledge-admin.button>
            </div>

            <label class="grid min-h-40 cursor-pointer place-items-center rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 p-6 text-center transition hover:border-brand-600 hover:bg-brand-50">
                <span><span class="block text-sm font-semibold text-slate-900">Arraste os PDFs aqui</span><span class="mt-1 block text-sm text-slate-500">ou clique para selecionar · até {{ $maxBatchMegabytes }} MB somando todos os arquivos</span></span>
                <input class="sr-only" type="file" accept="application/pdf,.pdf" multiple wire:model="files">
            </label>
            @error('files')<span class="text-sm text-rose-700">{{ $message }}</span>@enderror
            @error('files.*')<span class="text-sm text-rose-700">{{ $message }}</span>@enderror

            <div x-show="uploading" x-cloak class="grid gap-2" aria-live="polite">
                <div class="flex justify-between text-sm text-slate-600"><span>Enviando arquivos</span><span x-text="progress + '%'">0%</span></div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full bg-brand-600 transition-all" x-bind:style="`width: ${progress}%`"></div></div>
            </div>

            @if ($files !== [])
                <div class="grid gap-2" aria-live="polite">
                    @foreach ($files as $file)
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 py-1 pl-3 pr-1 text-sm" wire:key="{{ $file->getFilename() }}">
                            <span class="min-w-0 truncate font-medium text-slate-800">{{ $file->getClientOriginalName() }}</span>
                            <span class="flex shrink-0 items-center gap-1">
                                <span class="text-slate-500">{{ number_format($file->getSize() / 1048576, 1, ',', '.') }} MB</span>
                                <x-knowledge-admin.button variant="quiet" wire:click="removeFile({{ \Illuminate\Support\Js::from($file->getFilename()) }})" aria-label="Remover {{ $file->getClientOriginalName() }} da seleção" loading-target="ingest,removeFile,clearFiles">Remover</x-knowledge-admin.button>
                            </span>
                        </div>
                    @endforeach
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm text-slate-600">{{ count($files) }} {{ count($files) === 1 ? 'arquivo' : 'arquivos' }} · {{ number_format(collect($files)->sum(fn ($file) => $file->getSize()) / 1048576, 1, ',', '.') }} MB</p>
                        @if (count($files) > 1)
                            <x-knowledge-admin.confirm-modal action="clearFiles" tone="danger" title="Limpar seleção?" :message="'Os '.count($files).' arquivos selecionados serão retirados da lista e nada será importado. Para importá-los depois, será preciso selecioná-los de novo.'" confirm-label="Limpar seleção" loading="Limpando…">
                                <x-knowledge-admin.button variant="quiet" loading-target="ingest,removeFile,clearFiles">Limpar seleção</x-knowledge-admin.button>
                            </x-knowledge-admin.confirm-modal>
                        @endif
                    </div>
                </div>
            @endif

            <div><x-knowledge-admin.button type="submit" loading-target="ingest,files" loading="Importando…">Iniciar importação</x-knowledge-admin.button></div>
        </x-knowledge-admin.section>
    </form>

    @if ($completedBatchId)
        <x-knowledge-admin.section :title="'Importação #'.$completedBatchId">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">@foreach ($summary as $status => $count)<div class="rounded-lg bg-slate-50 p-3"><x-knowledge-admin.status-badge :status="$status" /><p class="mt-2 text-2xl font-semibold text-slate-900">{{ $count }}</p></div>@endforeach</div>
            <div class="flex flex-wrap gap-2">
                <x-knowledge-admin.button :href="route('admin.knowledge.batches.show', $completedBatchId)">Ver importação</x-knowledge-admin.button>
                @if (($summary['PENDING'] ?? 0) > 0)<x-knowledge-admin.button variant="secondary" :href="route('admin.knowledge.pending.index')">Revisar pendências</x-knowledge-admin.button>@endif
                <x-knowledge-admin.button variant="secondary" wire:click="$set('completedBatchId', null)">Adicionar mais documentos</x-knowledge-admin.button>
            </div>
        </x-knowledge-admin.section>
    @endif

    @if ($sourceModalOpen)
        <x-knowledge-admin.modal title="Nova origem" description="Identifique a instituição e a coleção de onde os PDFs foram obtidos." close="closeSourceModal">
            <form wire:submit="createSource">
                <div class="grid gap-4 px-5 py-5 sm:grid-cols-2">
                    <x-knowledge-admin.input label="Nome" required wire:model="newSourceName" placeholder="Ex.: ANVISA" />
                    <x-knowledge-admin.input label="Base / coleção" required wire:model="newSourceCollection" placeholder="Ex.: Bulário Eletrônico" />
                    <x-knowledge-admin.select label="Tipo" required wire:model="newSourceType">@foreach ($sourceTypeOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-knowledge-admin.select>
                    <x-knowledge-admin.input label="País" optional wire:model="newSourceCountry" placeholder="Ex.: Brasil" />
                    <x-knowledge-admin.input type="url" label="URL oficial" optional wire:model="newSourceUrl" placeholder="https://" wrapper-class="sm:col-span-2" />
                    <x-knowledge-admin.textarea label="Descrição" optional wire:model="newSourceDescription" wrapper-class="sm:col-span-2" />
                </div>
                <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end">
                    <x-knowledge-admin.button variant="secondary" wire:click="closeSourceModal">Cancelar</x-knowledge-admin.button>
                    <x-knowledge-admin.button type="submit" loading-target="createSource" loading="Salvando…">Salvar origem</x-knowledge-admin.button>
                </div>
            </form>
        </x-knowledge-admin.modal>
    @endif
</x-knowledge-admin.shell>
