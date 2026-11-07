<x-knowledge-admin.shell title="Adicionar documentos" :subtitle="'Envie PDFs de bulas (até '.$maxBatchMegabytes.' MB por envio). Depois do envio, cada arquivo é lido e identificado sozinho; o que não for reconhecido vai para Pendências.'">
    @if ($feedback)
        <x-knowledge-admin.alert :tone="$errorId ? 'danger' : 'success'">
            <p class="font-medium">{{ $feedback }}</p>
            @if ($errorId)<p class="mt-1">Código de referência: {{ $errorId }}</p>@endif
        </x-knowledge-admin.alert>
    @endif

    {{--
        O botão de envio não pode nascer com `disabled` vindo do servidor junto com `wire:loading.attr="disabled"`: o Livewire
        guarda o estado do atributo quando o upload começa e o restaura ao terminar, por cima da re-renderização que já o
        tinha habilitado (o botão ficava preso em "desabilitado" após escolher vários arquivos). O estado fica só no Alpine:
        `hasFiles` acompanha a propriedade `files` pelo `$wire.$watch`, e `uploading` acompanha os eventos de upload.
        Arrastar e soltar chama `$wire.uploadMultiple` diretamente (sem simular o `change` do input), e acrescenta à seleção.
    --}}
    <form class="max-w-2xl" wire:submit="ingest"
        x-data="{
            uploading: false, progress: 0, dragging: false, hasFiles: {{ $files === [] ? 'false' : 'true' }}, rejected: [],
            start() { this.uploading = true; this.progress = 0; },
            stop(done = false) { this.uploading = false; this.progress = done ? 100 : this.progress; },
            drop(event) {
                this.dragging = false;
                const files = Array.from(event.dataTransfer?.files ?? []);
                const pdfs = files.filter(file => file.type === 'application/pdf' || /\.pdf$/i.test(file.name));
                this.rejected = files.filter(file => ! pdfs.includes(file)).map(file => file.name);
                if (pdfs.length === 0) { return; }
                this.start();
                $wire.uploadMultiple('files', pdfs,
                    () => this.stop(true),
                    () => this.stop(),
                    (event) => { if (event.lengthComputable) { this.progress = Math.round(event.loaded * 100 / event.total); } },
                    () => this.stop());
            },
        }"
        x-init="$wire.$watch('files', value => hasFiles = Array.isArray(value) && value.length > 0)"
        x-on:livewire-upload-start="start()" x-on:livewire-upload-finish="stop(true)" x-on:livewire-upload-error="stop()" x-on:livewire-upload-cancel="stop()" x-on:livewire-upload-progress="progress = $event.detail.progress">
        <x-knowledge-admin.section title="Novo envio">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                <x-knowledge-admin.select label="Origem dos documentos" required wire:model="sourceId" hint="Instituição ou base de onde os documentos foram obtidos." wrapper-class="flex-1">
                    <option value="">Selecione a origem dos PDFs…</option>
                    @foreach ($sources as $source)<option value="{{ $source->id }}">{{ $source->displayName() }}</option>@endforeach
                </x-knowledge-admin.select>
                <x-knowledge-admin.button variant="secondary" wire:click="openSourceModal">+ Nova origem</x-knowledge-admin.button>
            </div>

            <label class="grid min-h-32 cursor-pointer place-items-center rounded-xl border-2 border-dashed border-brand-200 bg-brand-50/40 px-4 py-4 text-center transition hover:border-brand-600 hover:bg-brand-50 focus-within:ring-2 focus-within:ring-brand-600" x-bind:class="dragging ? 'border-brand-600 bg-brand-50' : ''" x-on:dragenter.prevent="dragging = true" x-on:dragover.prevent="dragging = true" x-on:dragleave.prevent="dragging = false" x-on:drop.prevent.stop="drop($event)" data-dropzone>
                <span>
                    <svg class="mx-auto size-8 text-brand-600 stroke-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" /></svg>
                    <span class="mt-1 block text-sm font-semibold text-slate-900" x-text="hasFiles ? 'Arraste mais PDFs aqui ou clique para acrescentar' : 'Arraste os PDFs aqui ou clique para escolher'">Arraste os PDFs aqui ou clique para escolher</span>
                    <span class="mt-0.5 block text-xs text-slate-500">Vários arquivos de uma vez · até {{ $maxBatchMegabytes }} MB no total</span>
                </span>
                <input class="sr-only" type="file" accept="application/pdf,.pdf" multiple wire:model="files">
            </label>
            <p x-show="rejected.length > 0" x-cloak class="text-sm text-amber-800" aria-live="polite">Só PDFs são aceitos. Ignorado: <span x-text="rejected.join(', ')"></span></p>
            @error('files')<span class="text-sm text-rose-700">{{ $message }}</span>@enderror
            @error('files.*')<span class="text-sm text-rose-700">{{ $message }}</span>@enderror

            <div x-show="uploading" x-cloak class="grid gap-2" aria-live="polite">
                <div class="flex justify-between text-sm text-slate-600"><span>Enviando arquivos</span><span x-text="progress + '%'">0%</span></div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full bg-brand-600 transition-all" x-bind:class="progress >= 100 ? 'w-full' : (progress >= 75 ? 'w-3/4' : (progress >= 50 ? 'w-1/2' : (progress >= 25 ? 'w-1/4' : 'w-1/12')))"></div></div>
            </div>

            @if ($files !== [])
                <div class="grid gap-2" aria-live="polite">
                    <x-knowledge-admin.data-table :columns="[['label' => 'Arquivo'], ['label' => 'Tamanho', 'align' => 'right'], ['label' => '', 'class' => 'w-px']]">
                        @foreach ($files as $file)
                            <tr wire:key="{{ $file->getFilename() }}">
                                <td class="max-w-md px-3 py-1.5"><span class="block truncate font-medium text-slate-800" title="{{ $file->getClientOriginalName() }}">{{ $file->getClientOriginalName() }}</span></td>
                                <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums text-slate-500">{{ number_format($file->getSize() / 1048576, 1, ',', '.') }} MB</td>
                                <td class="px-2 py-1"><x-knowledge-admin.row-actions><x-knowledge-admin.button variant="quiet" size="sm" wire:click="removeFile({{ \Illuminate\Support\Js::from($file->getFilename()) }})" aria-label="Remover {{ $file->getClientOriginalName() }} da seleção" loading-target="ingest,removeFile,clearFiles">Remover</x-knowledge-admin.button></x-knowledge-admin.row-actions></td>
                            </tr>
                        @endforeach
                    </x-knowledge-admin.data-table>
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

            <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                <x-knowledge-admin.button type="submit" loading-target="ingest" loading="Enviando…" x-bind:disabled="uploading || ! hasFiles" data-testid="upload-submit">Enviar {{ count($files) > 0 ? count($files).' '.(count($files) === 1 ? 'arquivo' : 'arquivos') : 'arquivos' }}</x-knowledge-admin.button>
                <p class="text-sm text-slate-500">Depois do envio, acompanhe em Importações; arquivos não reconhecidos aparecem em Pendências.</p>
            </div>
        </x-knowledge-admin.section>
    </form>

    @if ($completedBatchId)
        <x-knowledge-admin.section :title="'Importação concluída (#'.$completedBatchId.')'">
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
