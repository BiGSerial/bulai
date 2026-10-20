<x-knowledge-admin.shell title="Catálogo ANVISA" subtitle="Base oficial de medicamentos registrados, usada para validar os dados das bulas.">
    @php $lastFailed = $activeImport === null && $history->first()?->status === 'FAILED' ? $history->first() : null; @endphp

    @if ($feedback)
        <x-knowledge-admin.alert :tone="$feedbackTone"><p class="font-medium">{{ $feedback }}</p></x-knowledge-admin.alert>
    @endif

    @if ($totalRecords === 0)
        <x-knowledge-admin.alert tone="warning">
            <p class="font-medium">O catálogo está vazio.</p>
            <p class="mt-1">Enquanto ele não for importado, a validação regulatória fica indisponível e o princípio ativo não é completado pelo catálogo.</p>
        </x-knowledge-admin.alert>
    @endif

    @if ($lastFailed)
        <x-knowledge-admin.alert tone="danger">
            <p class="font-medium">A última importação falhou.</p>
            <p class="mt-1">Confira o arquivo e tente novamente. Código de referência: {{ $lastFailed->error_reference ?? 'indisponível' }}</p>
        </x-knowledge-admin.alert>
    @endif

    <x-knowledge-admin.section title="Situação do catálogo">
        <x-knowledge-admin.facts>
            <x-knowledge-admin.fact label="Registros no catálogo">{{ number_format($totalRecords, 0, ',', '.') }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Última importação concluída">{{ $lastCompleted?->completed_at?->format('d/m/Y H:i') ?? 'Nenhuma ainda' }}</x-knowledge-admin.fact>
            @if ($lastCompleted)
                <x-knowledge-admin.fact label="Arquivo">{{ $lastCompleted->original_filename }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Assinatura do arquivo (SHA-256)"><span class="break-all font-normal text-slate-600">{{ $lastCompleted->sha256 }}</span></x-knowledge-admin.fact>
            @endif
        </x-knowledge-admin.facts>
    </x-knowledge-admin.section>

    @if ($activeImport)
        <div wire:poll.3s>
            <x-knowledge-admin.section title="Importação em andamento">
                <div class="flex flex-wrap items-center gap-3">
                    <x-knowledge-admin.status-badge :status="$activeImport->status" />
                    <span class="text-sm text-slate-600">{{ $activeImport->original_filename }}</span>
                </div>
                <p class="text-sm text-slate-600" aria-live="polite">
                    @if ($activeImport->status === 'QUEUED')
                        Aguardando a vez na fila de processamento.
                    @else
                        {{ number_format($activeImport->rows_read, 0, ',', '.') }} linhas lidas · {{ number_format($activeImport->rows_inserted, 0, ',', '.') }} novas · {{ number_format($activeImport->rows_updated, 0, ',', '.') }} atualizadas
                    @endif
                </p>
            </x-knowledge-admin.section>
        </div>
    @endif

    <form class="max-w-3xl" wire:submit="import">
        <x-knowledge-admin.section title="Importar catálogo" description="Envie o arquivo DADOS_ABERTOS_MEDICAMENTOS.csv. A importação roda em segundo plano e pode ser repetida: registros existentes são atualizados.">
            <x-knowledge-admin.alert tone="info">
                Obtenha o arquivo no portal de dados abertos da ANVISA, no conjunto "Medicamentos registrados", e envie o CSV sem editá-lo.
            </x-knowledge-admin.alert>

            <label class="grid min-h-32 cursor-pointer place-items-center rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 p-6 text-center transition hover:border-brand-600 hover:bg-brand-50">
                <span>
                    <span class="block text-sm font-semibold text-slate-900">{{ $file ? $file->getClientOriginalName() : 'Selecione o arquivo CSV' }}</span>
                    <span class="mt-1 block text-sm text-slate-500">Formatos .csv ou .txt · até {{ $maxMegabytes }} MB</span>
                </span>
                <input class="sr-only" type="file" accept=".csv,.txt,text/csv,text/plain" wire:model="file">
            </label>
            @error('file')<span class="text-sm text-rose-700">{{ $message }}</span>@enderror

            <x-knowledge-admin.select label="Codificação do arquivo" required wire:model="encoding" hint="O arquivo original da ANVISA usa ISO-8859-1. Mude só se o arquivo foi convertido.">
                @foreach ($encodingOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </x-knowledge-admin.select>

            <div><x-knowledge-admin.button type="submit" loading-target="import,file" loading="Enviando…" :disabled="$activeImport !== null">Importar catálogo</x-knowledge-admin.button></div>
        </x-knowledge-admin.section>
    </form>

    <x-knowledge-admin.section title="Últimas importações">
        @if ($history->isEmpty())
            <x-knowledge-admin.empty-state>Nenhuma importação do catálogo foi feita ainda.</x-knowledge-admin.empty-state>
        @else
            <x-knowledge-admin.table :columns="['Data', 'Arquivo', 'Situação', 'Lidas', 'Novas', 'Atualizadas', 'Ignoradas', 'Rejeitadas', 'Enviado por']">
                @foreach ($history as $item)
                    <tr wire:key="import-{{ $item->id }}">
                        <td>{{ ($item->completed_at ?? $item->started_at)->format('d/m/Y H:i') }}</td>
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
