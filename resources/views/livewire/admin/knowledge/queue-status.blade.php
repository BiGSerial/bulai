<x-knowledge-admin.shell title="Fila e serviços" subtitle="O que está sendo analisado agora, o que travou e como destravar.">
    @if ($feedback)<x-knowledge-admin.alert :tone="$feedbackType">{{ $feedback }}</x-knowledge-admin.alert>@endif

    <div class="grid gap-5" wire:poll.5s x-data x-on:bulai-realtime.window="$wire.$refresh()">
        @php
            $worker = $queue['worker'];
            $workerTone = ['running' => 'success', 'idle' => 'success', 'stopped' => 'danger'][$worker['state']];
            $workerLabel = ['running' => 'Processando', 'idle' => 'Aguardando tarefas', 'stopped' => 'Parado'][$worker['state']];
            $names = ['local_ai' => 'IA local', 'ocr' => 'Leitura de PDF (OCR)'];
            $items = $queue['items'];
        @endphp

        @if ($worker['state'] === 'stopped')
            <x-knowledge-admin.alert tone="danger">Nenhum processador da fila está ativo: os arquivos ficam esperando. Em desenvolvimento, rode <code class="font-mono text-xs">php artisan queue:work --timeout=600 --tries=1</code>; em produção, verifique o contêiner <code class="font-mono text-xs">worker</code>.</x-knowledge-admin.alert>
        @endif

        <x-knowledge-admin.section title="Situação agora" :description="'Verificado às '.now()->timezone(config('app.timezone'))->format('H:i:s').'; esta tela se atualiza sozinha.'">
            <ul class="flex flex-wrap gap-2" aria-label="Serviços">
                <li class="inline-flex items-center gap-2 rounded-full bg-white py-1 pl-3 pr-1 text-sm text-slate-700 ring-1 ring-slate-200" data-testid="queue-worker" data-state="{{ $worker['state'] }}" title="{{ $worker['last_seen_at'] ? 'Último sinal '.\Illuminate\Support\Carbon::createFromTimestamp($worker['last_seen_at'])->diffForHumans().($worker['host'] ? ' · '.$worker['host'] : '') : 'Sem sinal' }}">
                    Processador da fila <x-knowledge-admin.badge :tone="$workerTone">{{ $workerLabel }}</x-knowledge-admin.badge>
                </li>
                @foreach ($names as $key => $name)
                    @php($health = $services[$key])
                    <li class="inline-flex items-center gap-2 rounded-full bg-white py-1 pl-3 pr-1 text-sm text-slate-700 ring-1 ring-slate-200" data-service="{{ $key }}" data-state="{{ $health->state }}" title="{{ $health->isWorking() ? 'Trabalhando agora'.($health->currentSeconds !== null ? ' há '.$health->currentSeconds.' s' : '') : 'Sem trabalho em andamento' }}{{ $health->restarts ? ' · reiniciado '.$health->restarts.'x' : '' }}">
                        {{ $name }} <x-knowledge-admin.badge :tone="$health->tone()">{{ $health->label() }}</x-knowledge-admin.badge>
                    </li>
                @endforeach
            </ul>
            <div class="grid grid-cols-3 gap-2">
                <x-knowledge-admin.kpi label="Na fila" :value="$queue['jobs']['waiting']" />
                <x-knowledge-admin.kpi label="Rodando" :value="$queue['jobs']['running']" />
                <x-knowledge-admin.kpi label="Com falha" :value="$queue['jobs']['failed']" :tone="$queue['jobs']['failed'] > 0 ? 'danger' : 'default'" />
            </div>
        </x-knowledge-admin.section>

        <x-knowledge-admin.section title="Análises em andamento" :description="count($items) === 0 ? 'Nada na fila agora.' : (count($items) === 1 ? '1 arquivo esperando ou sendo analisado.' : count($items).' arquivos esperando ou sendo analisados.')">
            @if ($queue['stale'] > 0)
                <x-slot:actions>
                    <x-knowledge-admin.confirm-modal action="requeueStale" title="Reenfileirar os parados?" :message="$queue['stale'].' item(ns) sem andamento há mais de '.config('knowledge.processing.progress_stale_minutes', 5).' minutos voltarão para o início da fila.'" confirm-label="Reenfileirar parados" loading="Reenfileirando…">
                        <x-knowledge-admin.button variant="secondary">Reenfileirar {{ $queue['stale'] }} parado(s)</x-knowledge-admin.button>
                    </x-knowledge-admin.confirm-modal>
                </x-slot:actions>
            @endif
            @if ($items === [])
                <x-knowledge-admin.empty-state>Nenhuma análise em andamento. Novos envios aparecem aqui assim que entram na fila.</x-knowledge-admin.empty-state>
            @else
                <ul class="grid gap-2">
                    @foreach ($items as $item)
                        <x-knowledge-admin.ops-row :title="$item['filename']" :support="$item['label'].' · '.$item['percent'].'%'.($item['stale'] ? ' · parado' : '').(! $item['has_job'] && $item['state'] === 'queued' ? ' · sem tarefa na fila' : '')" wire:key="queue-item-{{ $item['id'] }}">
                            <x-knowledge-admin.badge :tone="$item['stale'] ? 'warning' : ($item['state'] === 'processing' ? 'info' : 'neutral')">{{ $item['stale'] ? 'Parado' : ($item['state'] === 'processing' ? 'Processando' : 'Na fila') }}</x-knowledge-admin.badge>
                            <x-knowledge-admin.confirm-modal :action="'requeueItem('.$item['id'].')'" title="Reenfileirar este arquivo?" :message="$item['filename'].' volta para o início da fila e a análise atual, se houver, é descartada.'" confirm-label="Reenfileirar" loading="Enfileirando…">
                                <x-knowledge-admin.button variant="secondary">Reenfileirar</x-knowledge-admin.button>
                            </x-knowledge-admin.confirm-modal>
                            <x-knowledge-admin.confirm-modal :action="'cancelItem('.$item['id'].')'" tone="danger" title="Cancelar a análise?" :message="'A análise de '.$item['filename'].' é interrompida; o arquivo continua pendente para revisão manual.'" confirm-label="Cancelar análise" loading="Cancelando…">
                                <x-knowledge-admin.button variant="quiet">Cancelar</x-knowledge-admin.button>
                            </x-knowledge-admin.confirm-modal>
                        </x-knowledge-admin.ops-row>
                    @endforeach
                </ul>
            @endif
        </x-knowledge-admin.section>
    </div>

    <x-knowledge-admin.ops-disclosure title="Reiniciar serviços" summary="Use só se um serviço travar. A análise em andamento nele é interrompida.">
        <ul class="grid gap-2">
            @foreach ($names as $key => $name)
                <x-knowledge-admin.ops-row :title="$name" :support="$restartable[$key] ? 'Reinício remoto disponível.' : 'Reinício remoto não configurado para este serviço.'">
                    @if ($restartable[$key])
                        <x-knowledge-admin.confirm-modal :title="'Reiniciar '.$name.'?'" :message="'O serviço '.$name.' ficará indisponível por alguns instantes e a análise em andamento nele será interrompida.'" confirm-label="Reiniciar" tone="danger" :action="'restartService(\''.$key.'\')'" loading="Reiniciando…">
                            <x-knowledge-admin.button variant="secondary">Reiniciar</x-knowledge-admin.button>
                        </x-knowledge-admin.confirm-modal>
                    @endif
                </x-knowledge-admin.ops-row>
            @endforeach
        </ul>
    </x-knowledge-admin.ops-disclosure>
</x-knowledge-admin.shell>
