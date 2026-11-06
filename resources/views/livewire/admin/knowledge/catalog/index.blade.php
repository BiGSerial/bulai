<x-knowledge-admin.shell title="Catálogo ANVISA" subtitle="Lista oficial de medicamentos registrados, usada para conferir os dados das bulas.">
    @php $lastFailed = $activeImport === null && $history->first()?->status === 'FAILED' ? $history->first() : null; @endphp

    @if ($feedback)<x-knowledge-admin.alert :tone="$feedbackTone">{{ $feedback }}</x-knowledge-admin.alert>@endif
    @if ($totalRecords === 0)
        <x-knowledge-admin.alert tone="warning">O catálogo está vazio: a validação regulatória fica indisponível e o princípio ativo não é completado pelo catálogo até a importação.</x-knowledge-admin.alert>
    @endif
    @if ($lastFailed)
        <x-knowledge-admin.alert tone="danger">A última importação falhou. Confira o arquivo e tente novamente. Código de referência: {{ $lastFailed->error_reference ?? 'indisponível' }}</x-knowledge-admin.alert>
    @endif

    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3" aria-label="Resumo do catálogo">
        <x-knowledge-admin.kpi label="Registros no catálogo" :value="number_format($totalRecords, 0, ',', '.')" />
        <x-knowledge-admin.kpi label="Última importação concluída" :value="$lastCompleted?->completed_at?->format('d/m/Y') ?? 'Nenhuma'" :hint="$lastCompleted?->completed_at?->format('H:i')" />
        <x-knowledge-admin.kpi label="Arquivo" :value="$lastCompleted ? \Illuminate\Support\Str::limit($lastCompleted->original_filename, 28) : '—'" :hint="$lastCompleted ? 'SHA-256 '.substr($lastCompleted->sha256, 0, 12).'…' : null" />
    </div>

    @if ($activeImport)
        <div wire:poll.3s>
            <x-knowledge-admin.section title="Importação em andamento">
                <ul class="grid gap-2">
                    <x-knowledge-admin.ops-row :title="$activeImport->original_filename" :support="$activeImport->status === 'QUEUED' ? 'Aguardando a vez na fila de processamento.' : number_format($activeImport->rows_read, 0, ',', '.').' linhas lidas · '.number_format($activeImport->rows_inserted, 0, ',', '.').' novas · '.number_format($activeImport->rows_updated, 0, ',', '.').' atualizadas'">
                        <x-knowledge-admin.status-badge :status="$activeImport->status" />
                    </x-knowledge-admin.ops-row>
                </ul>
            </x-knowledge-admin.section>
        </div>
    @endif

    <x-knowledge-admin.ops-disclosure title="Importar arquivo" summary="Envie DADOS_ABERTOS_MEDICAMENTOS.csv do portal de dados abertos da ANVISA. Pode repetir: registros existentes são atualizados." :open="$totalRecords === 0 || $lastFailed !== null">
        <form class="grid gap-3" wire:submit="import">
            <label class="grid min-h-20 cursor-pointer place-items-center rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-center transition hover:border-brand-600 hover:bg-brand-50 focus-within:ring-2 focus-within:ring-brand-600">
                <span>
                    <span class="block text-sm font-semibold text-slate-900">{{ $file ? $file->getClientOriginalName() : 'Selecione o arquivo CSV' }}</span>
                    <span class="mt-1 block text-sm text-slate-500">.csv ou .txt · até {{ $maxMegabytes }} MB · envie o arquivo sem editá-lo</span>
                </span>
                <input class="sr-only" type="file" accept=".csv,.txt,text/csv,text/plain" wire:model="file">
            </label>
            @error('file')<span class="text-sm text-rose-700" role="alert">{{ $message }}</span>@enderror
            <x-knowledge-admin.select label="Codificação do arquivo" required wire:model="encoding" hint="O arquivo original da ANVISA usa ISO-8859-1. Mude só se o arquivo foi convertido." wrapper-class="sm:max-w-sm">
                @foreach ($encodingOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </x-knowledge-admin.select>
            <div><x-knowledge-admin.button type="submit" loading-target="import,file" loading="Enviando…" :disabled="$activeImport !== null">Importar catálogo</x-knowledge-admin.button></div>
        </form>
    </x-knowledge-admin.ops-disclosure>

    <x-knowledge-admin.section title="Últimas importações">
        @if ($history->isEmpty())
            <x-knowledge-admin.empty-state>Nenhuma importação do catálogo foi feita ainda.</x-knowledge-admin.empty-state>
        @else
            <x-knowledge-admin.data-table :columns="[['label' => 'Arquivo'], ['label' => 'Data'], ['label' => 'Lidas', 'align' => 'right'], ['label' => 'Novas', 'align' => 'right'], ['label' => 'Atualizadas', 'align' => 'right'], ['label' => 'Rejeitadas', 'align' => 'right'], ['label' => 'Por'], ['label' => 'Situação']]">
                @foreach ($history as $item)
                    <tr wire:key="import-{{ $item->id }}" class="hover:bg-brand-50/40">
                        <td class="max-w-xs px-3 py-2"><span class="block truncate font-semibold text-slate-900" title="{{ $item->original_filename }}">{{ $item->original_filename }}</span></td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ ($item->completed_at ?? $item->started_at)->format('d/m/Y H:i') }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">{{ number_format($item->rows_read, 0, ',', '.') }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">{{ number_format($item->rows_inserted, 0, ',', '.') }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">{{ number_format($item->rows_updated, 0, ',', '.') }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums {{ $item->rows_rejected > 0 ? 'text-amber-800' : 'text-slate-500' }}">{{ number_format($item->rows_rejected, 0, ',', '.') }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ $item->uploader?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2"><x-knowledge-admin.status-badge :status="$item->status" /></td>
                    </tr>
                @endforeach
            </x-knowledge-admin.data-table>
        @endif
    </x-knowledge-admin.section>
</x-knowledge-admin.shell>
