<x-knowledge-admin.shell title="Fila e serviços" subtitle="O que está sendo analisado agora, o que travou e como destravar. A tela se atualiza sozinha.">
    @if ($feedback)<x-knowledge-admin.alert :tone="$feedbackType">{{ $feedback }}</x-knowledge-admin.alert>@endif

    <div class="grid gap-4" wire:poll.5s x-data x-on:bulai-realtime.window="$wire.$refresh()">
        @php
            $worker = $queue['worker'];
            $workerTone = ['running' => 'success', 'idle' => 'success', 'stopped' => 'danger'][$worker['state']];
            $workerLabel = ['running' => 'Processando', 'idle' => 'Aguardando tarefas', 'stopped' => 'Parado'][$worker['state']];
            $names = ['local_ai' => 'IA local', 'ocr' => 'Leitura de PDF (OCR)'];
            $items = $queue['items'];
            $stateLabels = ['queued' => 'Na fila', 'processing' => 'Processando'];
        @endphp

        @if ($worker['state'] === 'stopped')
            <x-knowledge-admin.callout tone="danger" title="Nenhum processador da fila está ativo">Os arquivos ficam esperando até um processador voltar. Em desenvolvimento, rode <code class="font-mono text-xs">php artisan queue:work --timeout=600 --tries=1</code>; em produção, verifique o contêiner <code class="font-mono text-xs">worker</code>.</x-knowledge-admin.callout>
        @endif

        <section class="grid grid-cols-2 gap-2 lg:grid-cols-4" aria-label="Indicadores da fila">
            <x-knowledge-admin.kpi label="Na fila" :value="$queue['jobs']['waiting']" hint="Tarefas esperando um processador" />
            <x-knowledge-admin.kpi label="Processando" :value="$queue['jobs']['running']" hint="Tarefas em execução agora" :tone="$queue['jobs']['running'] > 0 ? 'brand' : 'default'" />
            <x-knowledge-admin.kpi label="Com falha" :value="$queue['jobs']['failed']" hint="Tarefas que falharam e não voltaram" :tone="$queue['jobs']['failed'] > 0 ? 'danger' : 'default'" />
            <x-knowledge-admin.kpi label="Resolvidos hoje" :value="$resolvedToday" hint="Arquivos que saíram da fila hoje" :tone="$resolvedToday > 0 ? 'success' : 'default'" />
        </section>

        <ul class="flex flex-wrap items-center gap-2 text-sm text-slate-700" aria-label="Serviços">
            <li class="inline-flex items-center gap-2 rounded-full bg-white py-1 pl-3 pr-1 ring-1 ring-slate-200" data-testid="queue-worker" data-state="{{ $worker['state'] }}" title="{{ $worker['last_seen_at'] ? 'Último sinal '.\Illuminate\Support\Carbon::createFromTimestamp($worker['last_seen_at'])->diffForHumans().($worker['host'] ? ' · '.$worker['host'] : '') : 'Sem sinal' }}">
                Processador da fila <x-knowledge-admin.badge :tone="$workerTone">{{ $workerLabel }}</x-knowledge-admin.badge>
            </li>
            @foreach ($names as $key => $name)
                @php($health = $services[$key])
                <li class="inline-flex items-center gap-2 rounded-full bg-white py-1 pl-3 pr-1 ring-1 ring-slate-200" data-service="{{ $key }}" data-state="{{ $health->state }}" title="{{ $health->isWorking() ? 'Trabalhando agora'.($health->currentSeconds !== null ? ' há '.$health->currentSeconds.' s' : '') : 'Sem trabalho em andamento' }}{{ $health->restarts ? ' · reiniciado '.$health->restarts.'x' : '' }}">
                    {{ $name }} <x-knowledge-admin.badge :tone="$health->tone()">{{ $health->label() }}</x-knowledge-admin.badge>
                </li>
            @endforeach
            <li class="ml-auto text-xs text-slate-500">Verificado às {{ now()->timezone(config('app.timezone'))->format('H:i:s') }}</li>
        </ul>

        <x-knowledge-admin.toolbar :count="count($items) === 0 ? 'Nada na fila agora' : (count($items) === 1 ? '1 arquivo esperando ou em análise' : count($items).' arquivos esperando ou em análise').($queue['stale'] > 0 ? ' · '.$queue['stale'].' parado(s)' : '')">
            <p class="text-sm font-semibold text-slate-800">Análises em andamento</p>
            @if ($queue['stale'] > 0)
                <x-slot:actions>
                    <x-knowledge-admin.confirm-modal action="requeueStale" title="Reenfileirar os parados?" :message="$queue['stale'].' item(ns) sem andamento há mais de '.config('knowledge.processing.progress_stale_minutes', 5).' minutos voltarão para o início da fila.'" confirm-label="Reenfileirar parados" loading="Reenfileirando…">
                        <x-knowledge-admin.button variant="secondary">Reenfileirar {{ $queue['stale'] }} parado(s)</x-knowledge-admin.button>
                    </x-knowledge-admin.confirm-modal>
                </x-slot:actions>
            @endif
        </x-knowledge-admin.toolbar>

        <x-knowledge-admin.data-table :columns="[['label' => 'Arquivo'], ['label' => 'Etapa'], ['label' => 'Andamento', 'align' => 'right'], ['label' => 'Situação'], ['label' => 'Atualizado'], ['label' => 'Ações', 'align' => 'right']]" dense>
            @foreach ($items as $item)
                <tr wire:key="queue-item-{{ $item['id'] }}" class="{{ $item['stale'] ? 'bg-amber-50' : '' }}">
                    <td class="max-w-xs"><span class="block truncate font-medium text-slate-900" title="{{ $item['filename'] }}">{{ $item['filename'] }}</span></td>
                    <td class="text-slate-700">{{ $item['label'] !== '' ? $item['label'] : '—' }}@if (! $item['has_job'] && $item['state'] === 'queued') <span class="text-xs text-amber-800">· sem tarefa na fila</span>@endif</td>
                    <td class="text-right tabular-nums text-slate-700">{{ $item['percent'] }}%</td>
                    <td><x-knowledge-admin.badge :tone="$item['stale'] ? 'warning' : ($item['state'] === 'processing' ? 'info' : 'neutral')">{{ $item['stale'] ? 'Parado' : ($stateLabels[$item['state']] ?? 'Na fila') }}</x-knowledge-admin.badge></td>
                    <td class="whitespace-nowrap text-xs text-slate-500">{{ $item['updated_at'] ? \Illuminate\Support\Carbon::parse($item['updated_at'])->diffForHumans() : '—' }}</td>
                    <td>
                        <x-knowledge-admin.row-actions>
                            <x-knowledge-admin.confirm-modal :action="'requeueItem('.$item['id'].')'" title="Reenfileirar este arquivo?" :message="$item['filename'].' volta para o início da fila e a análise atual, se houver, é descartada.'" confirm-label="Reenfileirar" loading="Enfileirando…">
                                <x-knowledge-admin.button variant="quiet" size="sm">Reenfileirar</x-knowledge-admin.button>
                            </x-knowledge-admin.confirm-modal>
                            <x-knowledge-admin.confirm-modal :action="'cancelItem('.$item['id'].')'" tone="danger" title="Cancelar a análise?" :message="'A análise de '.$item['filename'].' é interrompida; o arquivo continua pendente para revisão manual.'" confirm-label="Cancelar análise" loading="Cancelando…">
                                <x-knowledge-admin.button variant="quiet" size="sm">Cancelar</x-knowledge-admin.button>
                            </x-knowledge-admin.confirm-modal>
                        </x-knowledge-admin.row-actions>
                    </td>
                </tr>
            @endforeach
            @if ($items === [])
                <x-slot:empty>Nenhuma análise em andamento. Novos envios aparecem aqui assim que entram na fila.</x-slot:empty>
            @endif
        </x-knowledge-admin.data-table>
    </div>

    <x-knowledge-admin.ops-disclosure title="Reiniciar serviços" summary="Use só se um serviço travar. A análise em andamento nele é interrompida.">
        <x-knowledge-admin.data-table :columns="[['label' => 'Serviço'], ['label' => 'Reinício remoto'], ['label' => 'Ação', 'align' => 'right']]" dense>
            @foreach ($names as $key => $name)
                <tr wire:key="svc-{{ $key }}">
                    <td class="font-medium text-slate-900">{{ $name }}</td>
                    <td class="text-slate-700">{{ $restartable[$key] ? 'Disponível' : 'Não configurado para este serviço' }}</td>
                    <td>
                        <x-knowledge-admin.row-actions>
                            @if ($restartable[$key])
                                <x-knowledge-admin.confirm-modal :title="'Reiniciar '.$name.'?'" :message="'O serviço '.$name.' ficará indisponível por alguns instantes e a análise em andamento nele será interrompida.'" confirm-label="Reiniciar" tone="danger" :action="'restartService(\''.$key.'\')'" loading="Reiniciando…">
                                    <x-knowledge-admin.button variant="quiet" size="sm">Reiniciar</x-knowledge-admin.button>
                                </x-knowledge-admin.confirm-modal>
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </x-knowledge-admin.row-actions>
                    </td>
                </tr>
            @endforeach
        </x-knowledge-admin.data-table>
    </x-knowledge-admin.ops-disclosure>
</x-knowledge-admin.shell>
