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
                    @if ($health->hint() !== null)<span class="pr-2 text-xs text-slate-500" data-hint>{{ $health->hint() }}</span>@endif
                </li>
            @endforeach
            @php($observed = collect($services)->pluck('observedAt')->filter()->min())
            <li class="ml-auto text-xs text-slate-500">Sondado às {{ ($observed ? \Illuminate\Support\Carbon::createFromTimestamp($observed) : now())->timezone(config('app.timezone'))->format('H:i:s') }}</li>
        </ul>

        {{-- Cada tarefa da fila: a que está rodando agora no topo e as que esperam, na ordem em que serão processadas. --}}
        @php($taskRows = [...$tasks['running'], ...$tasks['waiting']])
        @php($ago = fn (?int $timestamp): string => $timestamp === null ? '—' : (abs(now()->getTimestamp() - $timestamp) < 60 ? 'menos de 1 min' : \Illuminate\Support\Carbon::createFromTimestamp($timestamp)->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, parts: 2)))
        <x-knowledge-admin.toolbar :count="($tasks['running'] === [] ? 'Nada rodando' : (count($tasks['running']) === 1 ? '1 rodando agora' : count($tasks['running']).' rodando agora')).' · '.($tasks['waiting_total'] === 1 ? '1 esperando' : $tasks['waiting_total'].' esperando').($tasks['waiting_total'] > count($tasks['waiting']) ? ' (mostrando as '.count($tasks['waiting']).' primeiras)' : '')">
            <p class="text-sm font-semibold text-slate-800">Tarefas na fila</p>
        </x-knowledge-admin.toolbar>

        <x-knowledge-admin.data-table :columns="[['label' => 'Ordem', 'align' => 'right'], ['label' => 'Tarefa'], ['label' => 'Sobre'], ['label' => 'Situação'], ['label' => 'Tempo', 'align' => 'right']]" dense data-testid="queue-tasks">
            @foreach ($taskRows as $task)
                @php($position = $task['state'] === 'running' ? null : $loop->index - count($tasks['running']) + 1)
                <tr wire:key="task-{{ $task['uuid'] }}" class="{{ $task['state'] === 'running' ? 'bg-brand-50/60' : '' }}" data-state="{{ $task['state'] }}">
                    <td class="text-right tabular-nums text-slate-500">{{ $position ?? '▶' }}</td>
                    <td class="font-medium text-slate-900">{{ $task['label'] }}</td>
                    <td class="max-w-xs">
                        @if ($task['document_id'] !== null)
                            <a class="block truncate text-brand-700 hover:underline" href="{{ route('admin.documents.show', $task['document_id']) }}" wire:navigate title="{{ $task['subject'] }}">{{ $task['subject'] }}</a>
                        @else
                            <span class="block truncate text-slate-700" title="{{ $task['subject'] }}">{{ $task['subject'] }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($task['state'] === 'running')
                            <x-knowledge-admin.badge tone="info">Processando agora</x-knowledge-admin.badge>
                        @elseif ($task['state'] === 'delayed')
                            <x-knowledge-admin.badge tone="neutral">Agendada</x-knowledge-admin.badge>
                        @else
                            <x-knowledge-admin.badge tone="neutral">Na fila</x-knowledge-admin.badge>
                        @endif
                        @if ($task['attempts'] > 1)<span class="text-xs text-amber-800"> · tentativa {{ $task['attempts'] }}</span>@endif
                    </td>
                    <td class="whitespace-nowrap text-right text-xs tabular-nums text-slate-500">
                        @if ($task['state'] === 'running')
                            há {{ $ago($task['started_at']) }}
                        @elseif ($task['state'] === 'delayed')
                            começa em {{ $ago($task['available_at']) }}
                        @else
                            esperando há {{ $ago($task['queued_at']) }}
                        @endif
                    </td>
                </tr>
            @endforeach
            @if ($taskRows === [])
                <x-slot:empty>A fila está vazia. Cada tarefa aparece aqui assim que entra na fila, e a que estiver rodando fica no topo.</x-slot:empty>
            @endif
        </x-knowledge-admin.data-table>

        {{-- Só a forma de uma linha daqui em diante: o compilador do Blade engole tudo entre a diretiva de uma linha acima e o próximo fechamento de bloco PHP. --}}
        @php($documents = $queue['documents'])
        @php($documentsStale = count(array_filter($documents, fn (array $document): bool => $document['stale'])))
        <x-knowledge-admin.toolbar :count="count($documents) === 0 ? 'Nenhuma agora' : (count($documents) === 1 ? '1 bula em processamento' : count($documents).' bulas em processamento').($documentsStale > 0 ? ' · '.$documentsStale.' sem sinal' : '')">
            <p class="text-sm font-semibold text-slate-800">Bulas em processamento</p>
        </x-knowledge-admin.toolbar>

        <x-knowledge-admin.data-table :columns="[['label' => 'Documento'], ['label' => 'Etapa'], ['label' => 'Andamento'], ['label' => 'Situação'], ['label' => 'Decorrido', 'align' => 'right']]" dense data-testid="processing-documents">
            @foreach ($documents as $document)
                @php($elapsed = $document['elapsed_seconds'] < 60 ? $document['elapsed_seconds'].' s' : intdiv($document['elapsed_seconds'], 60).' min '.($document['elapsed_seconds'] % 60).' s')
                <tr wire:key="processing-document-{{ $document['id'] }}" class="{{ $document['stale'] ? 'bg-amber-50' : '' }}" data-step="{{ $document['step'] }}">
                    <td class="max-w-xs"><a class="block truncate font-medium text-brand-700 hover:underline" href="{{ route('admin.documents.show', $document['id']) }}" wire:navigate title="{{ $document['title'] }}">{{ $document['title'] }}</a></td>
                    <td class="text-slate-700">{{ $document['step_label'] }}</td>
                    <td class="text-slate-700">
                        @if ($document['progress'] !== null && ($document['progress']['total'] ?? 0) > 0)
                            <x-knowledge-admin.meter class="w-48" :label="'Página '.$document['progress']['page'].' de '.$document['progress']['total']" :rate="min(1, $document['progress']['page'] / $document['progress']['total'])" />
                        @else
                            {{ $document['summary'] !== '' ? $document['summary'] : '—' }}
                        @endif
                    </td>
                    <td><x-knowledge-admin.badge :tone="$document['stale'] ? 'warning' : 'info'">{{ $document['stale'] ? 'Sem sinal há mais de '.config('knowledge.processing.progress_stale_minutes', 5).' min' : 'Em andamento' }}</x-knowledge-admin.badge></td>
                    <td class="whitespace-nowrap text-right text-xs tabular-nums text-slate-500" title="{{ $document['started_at'] ? 'Começou '.\Illuminate\Support\Carbon::parse($document['started_at'])->diffForHumans() : '' }}">{{ $elapsed }}</td>
                </tr>
            @endforeach
            @if ($documents === [])
                <x-slot:empty>Nenhuma bula sendo processada agora. A etapa de cada bula aparece aqui assim que o processamento começa.</x-slot:empty>
            @endif
        </x-knowledge-admin.data-table>

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
