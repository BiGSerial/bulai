<x-knowledge-admin.shell title="Interações medicamentosas" subtitle="Base DDInter de interações entre fármacos, usada como informação complementar à bula.">
    @if ($feedback)
        <x-knowledge-admin.alert :tone="$feedbackTone"><p class="font-medium">{{ $feedback }}</p></x-knowledge-admin.alert>
    @endif

    @if ($total === 0)
        <x-knowledge-admin.alert tone="warning">
            <p class="font-medium">A base de interações está vazia.</p>
            <p class="mt-1">Envie os arquivos CSV do DDInter abaixo para começar.</p>
        </x-knowledge-admin.alert>
    @endif

    <x-knowledge-admin.section title="Situação da base">
        <x-knowledge-admin.facts>
            <x-knowledge-admin.fact label="Pares de fármacos">{{ number_format($total, 0, ',', '.') }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Fármacos distintos">{{ number_format($drugs, 0, ',', '.') }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Graves">{{ number_format($bySeverity['major'] ?? 0, 0, ',', '.') }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Moderadas">{{ number_format($bySeverity['moderate'] ?? 0, 0, ',', '.') }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Leves">{{ number_format($bySeverity['minor'] ?? 0, 0, ',', '.') }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Sem classificação">{{ number_format($bySeverity['unknown'] ?? 0, 0, ',', '.') }}</x-knowledge-admin.fact>
        </x-knowledge-admin.facts>
    </x-knowledge-admin.section>

    @if ($activeImports->isNotEmpty())
        <div wire:poll.3s>
            <x-knowledge-admin.section title="Importações em andamento">
                @foreach ($activeImports as $active)
                    <div class="flex flex-wrap items-center gap-3" wire:key="active-{{ $active->id }}">
                        <x-knowledge-admin.status-badge :status="$active->status" />
                        <span class="text-sm text-slate-600">{{ $active->original_filename }}</span>
                        <span class="text-sm text-slate-500" aria-live="polite">{{ $active->status === 'QUEUED' ? 'Aguardando a vez na fila.' : number_format($active->rows_read, 0, ',', '.').' linhas lidas' }}</span>
                    </div>
                @endforeach
            </x-knowledge-admin.section>
        </div>
    @endif

    <form class="max-w-3xl" wire:submit="import">
        <x-knowledge-admin.section title="Importar interações" description="Envie os arquivos ddinter_downloads_code_*.csv. Pode enviar vários de uma vez; o mesmo par que aparece em mais de um arquivo é guardado uma única vez.">
            <x-knowledge-admin.alert tone="info">
                Origem dos dados: DDInter. Confirme os termos de uso da base (uso acadêmico/não comercial) e cite a fonte ao exibir os dados. As interações complementam a bula e não a substituem.
            </x-knowledge-admin.alert>

            <label class="grid min-h-32 cursor-pointer place-items-center rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 p-6 text-center transition hover:border-brand-600 hover:bg-brand-50">
                <span>
                    <span class="block text-sm font-semibold text-slate-900">{{ count($files) > 0 ? count($files).' arquivo(s) selecionado(s)' : 'Selecione os arquivos CSV' }}</span>
                    <span class="mt-1 block text-sm text-slate-500">Formatos .csv ou .txt · até {{ $maxMegabytes }} MB cada · até {{ $maxFiles }} arquivos</span>
                </span>
                <input class="sr-only" type="file" multiple accept=".csv,.txt,text/csv,text/plain" wire:model="files">
            </label>
            @error('files')<span class="text-sm text-rose-700">{{ $message }}</span>@enderror
            @error('files.*')<span class="text-sm text-rose-700">{{ $message }}</span>@enderror

            <div><x-knowledge-admin.button type="submit" loading-target="import,files" loading="Enviando…">Importar interações</x-knowledge-admin.button></div>
        </x-knowledge-admin.section>
    </form>

    <x-knowledge-admin.section title="Últimas importações">
        @if ($history->isEmpty())
            <x-knowledge-admin.empty-state>Nenhuma importação de interações foi feita ainda.</x-knowledge-admin.empty-state>
        @else
            <x-knowledge-admin.table :columns="['Data', 'Arquivo', 'Situação', 'Lidas', 'Novas', 'Atualizadas', 'Duplicadas', 'Rejeitadas', 'Enviado por']">
                @foreach ($history as $item)
                    <tr wire:key="import-{{ $item->id }}">
                        <td>{{ ($item->completed_at ?? $item->started_at)?->format('d/m/Y H:i') }}</td>
                        <td class="break-all">{{ $item->original_filename }}</td>
                        <td><x-knowledge-admin.status-badge :status="$item->status" /></td>
                        <td>{{ number_format($item->rows_read, 0, ',', '.') }}</td>
                        <td>{{ number_format($item->rows_inserted, 0, ',', '.') }}</td>
                        <td>{{ number_format($item->rows_updated, 0, ',', '.') }}</td>
                        <td>{{ number_format($item->rows_skipped, 0, ',', '.') }}</td>
                        <td>{{ number_format($item->rows_rejected, 0, ',', '.') }}</td>
                        <td>{{ $item->uploader?->name ?? '—' }}</td>
                    </tr>
                @endforeach
            </x-knowledge-admin.table>
        @endif
    </x-knowledge-admin.section>
</x-knowledge-admin.shell>
