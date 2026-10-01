<x-knowledge-admin.shell title="Adicionar documentos">
    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <div class="max-w-3xl">
            <h2 class="text-lg font-semibold text-slate-950">Nova importação</h2>
            <p class="mt-1 text-sm text-slate-600">Selecione a origem e envie até {{ $maxFiles }} PDFs. Arquivos associados serão processados em segundo plano.</p>

            @if ($feedback)
                <div class="mt-5 rounded-xl border px-4 py-3 text-sm {{ $errorId ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}" role="status">
                    <p class="font-semibold">{{ $feedback }}</p>
                    @if ($errorId)<p class="mt-1">Código de referência: {{ $errorId }}</p>@endif
                </div>
            @endif

            <form class="mt-6 grid gap-5" wire:submit="ingest" x-data="{ uploading: false, progress: 0 }" x-on:livewire-upload-start="uploading = true" x-on:livewire-upload-finish="uploading = false; progress = 100" x-on:livewire-upload-error="uploading = false" x-on:livewire-upload-progress="progress = $event.detail.progress">
                <div class="grid gap-2"><div class="flex flex-col gap-2 sm:flex-row sm:items-end"><label class="grid flex-1 gap-2 text-sm font-semibold text-slate-800">Origem dos documentos<select class="rounded-lg border-slate-300 bg-white text-slate-950 shadow-sm focus:border-teal-600 focus:ring-teal-600" wire:model="sourceId"><option value="">Selecione a origem dos PDFs...</option>@foreach ($sources as $source)<option value="{{ $source->id }}">{{ $source->displayName() }}</option>@endforeach</select></label><button class="rounded-lg border border-teal-700 px-4 py-2.5 text-sm font-semibold text-teal-800 hover:bg-teal-50" type="button" wire:click="openSourceModal">+ Nova origem</button></div><p class="text-sm text-slate-500">Indique o órgão, instituição ou base de onde estes documentos foram obtidos.</p>@error('sourceId')<span class="text-sm text-red-700">{{ $message }}</span>@enderror</div>

                <label class="group grid min-h-48 cursor-pointer place-items-center rounded-2xl border-2 border-dashed border-slate-300 bg-slate-50 p-6 text-center transition hover:border-teal-500 hover:bg-teal-50/40">
                    <span><span class="block text-base font-semibold text-slate-900">Arraste os PDFs aqui</span><span class="mt-1 block text-sm text-slate-500">ou selecione os arquivos · máximo {{ $maxFileMegabytes }} MB por PDF</span></span>
                    <input class="sr-only" type="file" accept="application/pdf,.pdf" multiple wire:model="files">
                </label>
                @error('files')<span class="text-sm text-red-700">{{ $message }}</span>@enderror
                @error('files.*')<span class="text-sm text-red-700">{{ $message }}</span>@enderror

                <div x-show="uploading" x-cloak class="grid gap-2" aria-live="polite">
                    <div class="flex justify-between text-sm text-slate-600"><span>Enviando arquivos</span><span x-text="progress + '%'">0%</span></div>
                    <div class="h-2 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full bg-teal-600 transition-all" x-bind:style="`width: ${progress}%`"></div></div>
                </div>

                @if ($files !== [])
                    <div class="grid gap-2" aria-live="polite">
                        @foreach ($files as $file)<div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm"><span class="min-w-0 truncate font-medium text-slate-800">{{ $file->getClientOriginalName() }}</span><span class="shrink-0 text-slate-500">{{ number_format($file->getSize() / 1048576, 1, ',', '.') }} MB</span></div>@endforeach
                        <p class="text-sm text-slate-600">{{ count($files) }} arquivo(s) · {{ number_format(collect($files)->sum(fn ($file) => $file->getSize()) / 1048576, 1, ',', '.') }} MB</p>
                    </div>
                @endif

                <button class="justify-self-start rounded-lg bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60" type="submit" wire:loading.attr="disabled" wire:target="ingest,files"><span wire:loading.remove wire:target="ingest">Iniciar ingestão</span><span wire:loading wire:target="ingest">Processando lote…</span></button>
            </form>
        </div>
    </section>

    @if ($completedBatchId)
        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
            <h2 class="text-lg font-semibold text-slate-950">Batch #{{ $completedBatchId }}</h2>
            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">@foreach ($summary as $status => $count)<div class="rounded-xl bg-slate-50 p-3"><x-knowledge-admin.status-badge :status="$status" /><p class="mt-2 text-2xl font-semibold text-slate-950">{{ $count }}</p></div>@endforeach</div>
            <div class="mt-5 flex flex-wrap gap-3 text-sm font-semibold"><a class="rounded-lg bg-teal-700 px-4 py-2 text-white" href="{{ route('admin.knowledge.batches.show', $completedBatchId) }}" wire:navigate>Ver batch</a>@if (($summary['PENDING'] ?? 0) > 0)<a class="rounded-lg border border-slate-300 px-4 py-2 text-slate-800" href="{{ route('admin.knowledge.pending.index') }}" wire:navigate>Revisar pendentes</a>@endif<button class="rounded-lg border border-slate-300 px-4 py-2 text-slate-800" type="button" wire:click="$set('completedBatchId', null)">Adicionar mais documentos</button></div>
        </section>
    @endif

    @if ($sourceModalOpen)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/60 p-3 backdrop-blur-[1px] sm:items-center sm:p-6" role="dialog" aria-modal="true" aria-labelledby="new-source-title" wire:keydown.escape="closeSourceModal" wire:click="closeSourceModal">
            <div class="flex max-h-[calc(100vh-1.5rem)] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl sm:max-h-[calc(100vh-3rem)]" wire:click.stop>
                <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 sm:px-6 sm:py-5">
                    <div><h2 id="new-source-title" class="text-xl font-semibold text-slate-950">Nova origem</h2><p class="mt-1 text-sm leading-6 text-slate-600">Identifique o órgão e a coleção de onde os PDFs foram obtidos.</p></div>
                    <button class="shrink-0 rounded-lg border border-transparent p-2 text-slate-500 transition hover:border-slate-200 hover:bg-slate-100 hover:text-slate-800 focus:outline-none focus:ring-2 focus:ring-teal-600" type="button" wire:click="closeSourceModal" aria-label="Fechar modal">✕</button>
                </div>
                <form class="flex min-h-0 flex-1 flex-col" wire:submit="createSource">
                    <div class="grid gap-5 overflow-y-auto px-5 py-5 sm:px-6">
                        <div class="grid gap-5 sm:grid-cols-2">
                            <label class="grid content-start gap-2 text-sm font-semibold text-slate-800"><span>Nome <span class="text-red-700" aria-hidden="true">*</span></span><input class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 font-normal text-slate-950 shadow-sm outline-none placeholder:text-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20" type="text" wire:model="newSourceName" placeholder="Ex.: ANVISA">@error('newSourceName')<span class="font-normal text-red-700">{{ $message }}</span>@enderror</label>
                            <label class="grid content-start gap-2 text-sm font-semibold text-slate-800"><span>Base / coleção <span class="text-red-700" aria-hidden="true">*</span></span><input class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 font-normal text-slate-950 shadow-sm outline-none placeholder:text-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20" type="text" wire:model="newSourceCollection" placeholder="Ex.: Bulário Eletrônico">@error('newSourceCollection')<span class="font-normal text-red-700">{{ $message }}</span>@enderror</label>
                            <label class="grid content-start gap-2 text-sm font-semibold text-slate-800"><span>Tipo <span class="text-red-700" aria-hidden="true">*</span></span><select class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 font-normal text-slate-950 shadow-sm outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20" wire:model="newSourceType">@foreach($sourceTypeOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>@error('newSourceType')<span class="font-normal text-red-700">{{ $message }}</span>@enderror</label>
                            <label class="grid content-start gap-2 text-sm font-semibold text-slate-800"><span>País <span class="font-normal text-slate-500">(opcional)</span></span><input class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 font-normal text-slate-950 shadow-sm outline-none placeholder:text-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20" type="text" wire:model="newSourceCountry" placeholder="Ex.: Brasil">@error('newSourceCountry')<span class="font-normal text-red-700">{{ $message }}</span>@enderror</label>
                        </div>
                        <label class="grid gap-2 text-sm font-semibold text-slate-800"><span>URL da fonte <span class="font-normal text-slate-500">(opcional)</span></span><input class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 font-normal text-slate-950 shadow-sm outline-none placeholder:text-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20" type="url" wire:model="newSourceUrl" placeholder="https://www.exemplo.gov.br/base">@error('newSourceUrl')<span class="font-normal text-red-700">{{ $message }}</span>@enderror</label>
                        <label class="grid gap-2 text-sm font-semibold text-slate-800"><span>Descrição <span class="font-normal text-slate-500">(opcional)</span></span><textarea class="min-h-28 w-full resize-y rounded-lg border border-slate-300 bg-white px-3 py-2.5 font-normal leading-6 text-slate-950 shadow-sm outline-none placeholder:text-slate-400 focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20" wire:model="newSourceDescription" placeholder="Descreva brevemente a procedência ou o conteúdo da coleção."></textarea>@error('newSourceDescription')<span class="font-normal text-red-700">{{ $message }}</span>@enderror</label>
                    </div>
                    <div class="flex flex-col-reverse gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end sm:px-6"><button class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-100" type="button" wire:click="closeSourceModal">Cancelar</button><button class="rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60" type="submit" wire:loading.attr="disabled" wire:target="createSource"><span wire:loading.remove wire:target="createSource">Salvar origem</span><span wire:loading wire:target="createSource">Salvando…</span></button></div>
                </form>
            </div>
        </div>
    @endif
</x-knowledge-admin.shell>
