<x-knowledge-admin.shell title="Relatórios" subtitle="Escolha um tipo e gere; o arquivo fica na lista para baixar. Consultas e perguntas saem anonimizadas; a auditoria identifica contas, nunca senhas.">
    @if ($mensagem)<x-knowledge-admin.alert :tone="$tipoMensagem ?? 'info'">{{ $mensagem }}</x-knowledge-admin.alert>@endif

    <x-knowledge-admin.section title="Tipos de relatório">
        <ul class="grid gap-2" aria-label="Tipos de relatório">
            @foreach ($tipos as $key => $type)
                <li class="grid gap-3 rounded-lg bg-white px-3 py-2.5 ring-1 {{ $tipo === $key ? 'ring-brand-600' : 'ring-slate-200' }}" wire:key="tipo-{{ $key }}">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-900">{{ $type->label() }}</p>
                            <p class="text-xs text-slate-500">{{ $type->description() }}</p>
                        </div>
                        @if ($tipo !== $key)
                            <x-knowledge-admin.button variant="secondary" wire:click="$set('tipo', '{{ $key }}')" class="shrink-0">Gerar</x-knowledge-admin.button>
                        @endif
                    </div>
                    @if ($tipo === $key)
                        <form class="grid gap-3 border-t border-slate-200 pt-3 sm:flex sm:flex-wrap sm:items-end" wire:submit="gerar" aria-label="Opções do relatório {{ $type->label() }}">
                            <x-knowledge-admin.select label="Formato" wire:model="formato" wrapper-class="sm:w-32">
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
                    @endif
                </li>
            @endforeach
        </ul>
    </x-knowledge-admin.section>

    <x-knowledge-admin.section title="Relatórios gerados" :description="$gerando ? 'Há geração em curso; a lista se atualiza sozinha.' : 'Os mais recentes primeiro.'">
        <div @if ($gerando) wire:poll.3s @endif class="grid gap-2">
            @if ($reports->isEmpty())
                <x-knowledge-admin.empty-state>Nenhum relatório gerado ainda. Escolha um tipo acima e clique em Gerar.</x-knowledge-admin.empty-state>
            @else
                <ul class="grid gap-2">
                    @foreach ($reports as $report)
                        @php
                            $type = $tipos[$report->type] ?? null;
                            $support = $report->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i')
                                .(($report->parameters['from'] ?? null) ? ' · de '.\Illuminate\Support\Carbon::parse($report->parameters['from'])->format('d/m/Y') : '')
                                .(($report->parameters['to'] ?? null) ? ' · até '.\Illuminate\Support\Carbon::parse($report->parameters['to'])->format('d/m/Y') : '')
                                .($report->generator ? ' · por '.$report->generator->name : '')
                                .($report->status === 'COMPLETED' ? ' · '.number_format((int) $report->rows, 0, ',', '.').' '.($report->rows === 1 ? 'linha' : 'linhas').' · '.($report->size >= 1048576 ? number_format($report->size / 1048576, 1, ',', '.').' MB' : number_format($report->size / 1024, 1, ',', '.').' KB') : '')
                                .($report->status === 'FAILED' ? ' · código '.$report->error_code : '');
                        @endphp
                        <x-knowledge-admin.ops-row :title="($type?->label() ?? $report->type).' ('.strtoupper($report->format).')'" :support="$support" wire:key="rep-{{ $report->uuid }}">
                            <x-knowledge-admin.status-badge :status="$report->status" />
                            @if ($report->status === 'COMPLETED')
                                <x-knowledge-admin.button variant="secondary" :href="route('admin.reports.download', $report)" download>Baixar</x-knowledge-admin.button>
                            @endif
                            @if ($report->isFinished())
                                <x-knowledge-admin.confirm-modal action="apagar('{{ $report->uuid }}')" tone="danger" title="Apagar relatório?" message="O arquivo gerado será removido do servidor. Você pode gerá-lo novamente depois." confirm-label="Apagar relatório" loading="Apagando…">
                                    <x-knowledge-admin.button variant="quiet">Apagar</x-knowledge-admin.button>
                                </x-knowledge-admin.confirm-modal>
                            @endif
                        </x-knowledge-admin.ops-row>
                    @endforeach
                </ul>
            @endif
        </div>
        {{ $reports->links() }}
    </x-knowledge-admin.section>
</x-knowledge-admin.shell>
