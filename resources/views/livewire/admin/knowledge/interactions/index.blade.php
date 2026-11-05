<x-knowledge-admin.shell title="Interações medicamentosas" subtitle="Base DDInter de interações entre fármacos, usada como informação complementar à bula.">
    @if ($feedback)<x-knowledge-admin.alert :tone="$feedbackTone">{{ $feedback }}</x-knowledge-admin.alert>@endif
    @if ($total === 0)
        <x-knowledge-admin.alert tone="warning">A base de interações está vazia. Envie os arquivos CSV do DDInter para começar.</x-knowledge-admin.alert>
    @endif

    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
        <x-knowledge-admin.kpi label="Pares de fármacos" :value="number_format($total, 0, ',', '.')" :hint="number_format($drugs, 0, ',', '.').' fármacos distintos'" />
        <x-knowledge-admin.kpi label="Graves · moderadas · leves" :value="number_format($bySeverity['major'] ?? 0, 0, ',', '.').' · '.number_format($bySeverity['moderate'] ?? 0, 0, ',', '.').' · '.number_format($bySeverity['minor'] ?? 0, 0, ',', '.')" :hint="number_format($bySeverity['unknown'] ?? 0, 0, ',', '.').' sem classificação'" />
        <x-knowledge-admin.kpi label="Última importação concluída" :value="$history->firstWhere('status', 'COMPLETED')?->completed_at?->format('d/m/Y') ?? 'Nenhuma'" :hint="$history->firstWhere('status', 'COMPLETED')?->original_filename" />
    </div>

    @if ($activeImports->isNotEmpty())
        <div wire:poll.3s>
            <x-knowledge-admin.section title="Importações em andamento">
                <ul class="grid gap-2">
                    @foreach ($activeImports as $active)
                        <x-knowledge-admin.ops-row :title="$active->original_filename" :support="$active->status === 'QUEUED' ? 'Aguardando a vez na fila.' : number_format($active->rows_read, 0, ',', '.').' linhas lidas'" wire:key="active-{{ $active->id }}">
                            <x-knowledge-admin.status-badge :status="$active->status" />
                        </x-knowledge-admin.ops-row>
                    @endforeach
                </ul>
            </x-knowledge-admin.section>
        </div>
    @endif

    <x-knowledge-admin.ops-disclosure title="Importar arquivos" summary="Envie os arquivos ddinter_downloads_code_*.csv (vários de uma vez). O mesmo par em mais de um arquivo é guardado uma única vez." :open="$total === 0">
        <form class="grid gap-3" wire:submit="import">
            <label class="grid min-h-28 cursor-pointer place-items-center rounded-xl border border-dashed border-slate-300 bg-slate-50 p-5 text-center transition hover:border-brand-600 hover:bg-brand-50">
                <span>
                    <span class="block text-sm font-semibold text-slate-900">{{ count($files) > 0 ? count($files).' arquivo(s) selecionado(s)' : 'Selecione os arquivos CSV' }}</span>
                    <span class="mt-1 block text-sm text-slate-500">.csv ou .txt · até {{ $maxMegabytes }} MB cada · até {{ $maxFiles }} arquivos</span>
                </span>
                <input class="sr-only" type="file" multiple accept=".csv,.txt,text/csv,text/plain" wire:model="files">
            </label>
            @error('files')<span class="text-sm text-rose-700" role="alert">{{ $message }}</span>@enderror
            @error('files.*')<span class="text-sm text-rose-700" role="alert">{{ $message }}</span>@enderror
            <p class="text-xs text-slate-500">Origem dos dados: DDInter (uso acadêmico/não comercial; cite a origem ao exibir). As interações complementam a bula, não a substituem.</p>
            <div><x-knowledge-admin.button type="submit" loading-target="import,files" loading="Enviando…">Importar interações</x-knowledge-admin.button></div>
        </form>
    </x-knowledge-admin.ops-disclosure>

    <x-knowledge-admin.section title="Últimas importações">
        @if ($history->isEmpty())
            <x-knowledge-admin.empty-state>Nenhuma importação de interações foi feita ainda.</x-knowledge-admin.empty-state>
        @else
            <ul class="grid gap-2">
                @foreach ($history as $item)
                    <x-knowledge-admin.ops-row :title="$item->original_filename" :support="(($item->completed_at ?? $item->started_at)?->format('d/m/Y H:i') ?? '—').' · '.number_format($item->rows_read, 0, ',', '.').' lidas · '.number_format($item->rows_inserted, 0, ',', '.').' novas · '.number_format($item->rows_updated, 0, ',', '.').' atualizadas · '.number_format($item->rows_rejected, 0, ',', '.').' rejeitadas'.($item->uploader ? ' · '.$item->uploader->name : '')" wire:key="import-{{ $item->id }}">
                        <x-knowledge-admin.status-badge :status="$item->status" />
                    </x-knowledge-admin.ops-row>
                @endforeach
            </ul>
        @endif
    </x-knowledge-admin.section>
</x-knowledge-admin.shell>
