<x-knowledge-admin.shell title="Relatórios" subtitle="Escolha um tipo, gere e baixe. Consultas e perguntas saem anonimizadas; a auditoria identifica contas, nunca senhas.">
    @if ($mensagem)<x-knowledge-admin.alert :tone="$tipoMensagem ?? 'info'">{{ $mensagem }}</x-knowledge-admin.alert>@endif

    <x-knowledge-admin.section title="Tipos de relatório" description="Clique em Gerar para escolher o formato e, quando houver, o período.">
        <x-knowledge-admin.data-table :columns="[['label' => 'Relatório'], ['label' => 'O que contém'], ['label' => 'Formatos'], ['label' => 'Período'], ['label' => 'Ações', 'align' => 'right']]" dense>
            @foreach ($tipos as $key => $type)
                <tr wire:key="tipo-{{ $key }}" class="{{ $tipo === $key ? '!bg-brand-50/60' : '' }}">
                    <td>{{ $type->label() }}</td>
                    <td class="max-w-md !whitespace-normal text-slate-600">{{ $type->description() }}</td>
                    <td class="text-slate-600">{{ strtoupper(implode(' · ', $type->formats())) }}</td>
                    <td class="text-slate-600">{{ $type->acceptsPeriod() ? 'Por período' : 'Estado atual' }}</td>
                    <td><x-knowledge-admin.row-actions>
                        @if ($tipo !== $key)
                            <x-knowledge-admin.button variant="secondary" size="sm" wire:click="$set('tipo', '{{ $key }}')">Gerar</x-knowledge-admin.button>
                        @else
                            <x-knowledge-admin.badge tone="brand">Selecionado</x-knowledge-admin.badge>
                        @endif
                    </x-knowledge-admin.row-actions></td>
                </tr>
                @if ($tipo === $key)
                    <tr wire:key="form-{{ $key }}" class="!h-auto !bg-brand-50/60">
                        <td colspan="5" class="!whitespace-normal">
                            <form class="grid gap-3 py-1 sm:flex sm:flex-wrap sm:items-end" wire:submit="gerar" aria-label="Opções do relatório {{ $type->label() }}">
                                <x-knowledge-admin.select label="Formato" compact wire:model="formato" wrapper-class="sm:w-32">
                                    @foreach ($tipoAtual->formats() as $format)<option value="{{ $format }}">{{ strtoupper($format) }}</option>@endforeach
                                </x-knowledge-admin.select>
                                @if ($tipoAtual->acceptsPeriod())
                                    <x-knowledge-admin.input label="De" type="date" wire:model="de" optional wrapper-class="sm:w-44" />
                                    <x-knowledge-admin.input label="Até" type="date" wire:model="ate" optional wrapper-class="sm:w-44" />
                                @else
                                    <p class="text-xs text-slate-500 sm:pb-2">Sem período: traz o estado atual.</p>
                                @endif
                                <x-knowledge-admin.button type="submit" loading-target="gerar" loading="Gerando…">Gerar {{ strtoupper($formato) }}</x-knowledge-admin.button>
                            </form>
                        </td>
                    </tr>
                @endif
            @endforeach
        </x-knowledge-admin.data-table>
    </x-knowledge-admin.section>

    <x-knowledge-admin.section title="Relatórios gerados" :description="$gerando ? 'Há geração em curso; a lista se atualiza sozinha.' : 'Os mais recentes primeiro.'">
        <div @if ($gerando) wire:poll.3s @endif class="grid gap-2">
            <x-knowledge-admin.data-table :columns="[['label' => 'Relatório'], ['label' => 'Gerado em'], ['label' => 'Período'], ['label' => 'Por'], ['label' => 'Linhas', 'align' => 'right'], ['label' => 'Tamanho', 'align' => 'right'], ['label' => 'Situação'], ['label' => 'Ações', 'align' => 'right']]" dense>
                @foreach ($reports as $report)
                    @php
                        $type = $tipos[$report->type] ?? null;
                        $period = (($report->parameters['from'] ?? null) ? 'de '.\Illuminate\Support\Carbon::parse($report->parameters['from'])->format('d/m/Y') : '').(($report->parameters['to'] ?? null) ? ' até '.\Illuminate\Support\Carbon::parse($report->parameters['to'])->format('d/m/Y') : '');
                        $size = $report->status === 'COMPLETED' ? ($report->size >= 1048576 ? number_format($report->size / 1048576, 1, ',', '.').' MB' : number_format($report->size / 1024, 1, ',', '.').' KB') : '—';
                    @endphp
                    <tr wire:key="rep-{{ $report->uuid }}">
                        <td>{{ $type?->label() ?? $report->type }} <span class="text-xs font-normal text-slate-500">({{ strtoupper($report->format) }})</span></td>
                        <td class="tabular text-slate-600">{{ $report->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td>
                        <td class="text-slate-600">{{ trim($period) !== '' ? trim($period) : '—' }}</td>
                        <td class="text-slate-600">{{ $report->generator?->name ?? '—' }}</td>
                        <td class="tabular">{{ $report->status === 'COMPLETED' ? number_format((int) $report->rows, 0, ',', '.') : '—' }}</td>
                        <td class="tabular text-slate-600">{{ $size }}</td>
                        <td><x-knowledge-admin.status-badge :status="$report->status" />@if ($report->status === 'FAILED') <span class="text-xs text-rose-700">código {{ $report->error_code }}</span>@endif</td>
                        <td><x-knowledge-admin.row-actions>
                            @if ($report->status === 'COMPLETED')
                                <x-knowledge-admin.button variant="secondary" size="sm" :href="route('admin.reports.download', $report)" download>Baixar</x-knowledge-admin.button>
                            @endif
                            @if ($report->isFinished())
                                <x-knowledge-admin.confirm-modal action="apagar('{{ $report->uuid }}')" tone="danger" title="Apagar relatório?" message="O arquivo gerado será removido do servidor. Você pode gerá-lo novamente depois." confirm-label="Apagar relatório" loading="Apagando…">
                                    <x-knowledge-admin.button variant="quiet" size="sm">Apagar</x-knowledge-admin.button>
                                </x-knowledge-admin.confirm-modal>
                            @endif
                        </x-knowledge-admin.row-actions></td>
                    </tr>
                @endforeach
                @if ($reports->isEmpty())
                    <x-slot:empty>Nenhum relatório gerado ainda. Escolha um tipo acima e clique em Gerar.</x-slot:empty>
                @endif
            </x-knowledge-admin.data-table>
        </div>
        {{ $reports->links() }}
    </x-knowledge-admin.section>
</x-knowledge-admin.shell>
