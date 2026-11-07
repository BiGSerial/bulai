<x-knowledge-admin.shell title="Perguntas sem resposta" subtitle="O Perguntar não conseguiu responder estas perguntas mesmo depois de esgotar as fontes (busca por trechos e leitura da bula inteira). Adquira a fonte que falta e marque como resolvida.">
    {{-- Reativa: a lista se atualiza sozinha a cada 20 s (só com a aba visível) e quando a ação de linha termina. --}}
    <div class="grid gap-4" wire:poll.20s.visible>
        <x-knowledge-admin.tabs aria-label="Situação das perguntas" :active="$status" :tabs="[['key' => 'pending', 'label' => 'Pendentes', 'count' => $totals['pending']], ['key' => 'resolved', 'label' => 'Resolvidas', 'count' => $totals['resolved']], ['key' => 'dismissed', 'label' => 'Descartadas', 'count' => $totals['dismissed']]]" />

        <x-knowledge-admin.toolbar :count="$groups->total().' '.($groups->total() === 1 ? 'remédio' : 'remédios').' nesta situação'">
            <x-knowledge-admin.search compact label="Buscar" wire:model.live.debounce.300ms="search" placeholder="Nome do remédio" wrapper-class="w-full sm:w-80" />
        </x-knowledge-admin.toolbar>
        @if ($feedback)<x-knowledge-admin.alert tone="success">{{ $feedback }}</x-knowledge-admin.alert>@endif

        @if ($groups->isEmpty())
            <x-knowledge-admin.empty-state>
                @if ($isPending && trim($search) === '')Nenhuma pendência: todas as perguntas dos pacientes encontraram resposta nas bulas cadastradas.@else Nenhuma pergunta encontrada com estes filtros.@endif
            </x-knowledge-admin.empty-state>
        @else
            <div wire:loading.class="opacity-60" wire:target="search,status">
                <x-knowledge-admin.data-table :columns="[['label' => 'Remédio', 'class' => 'w-1/6'], ['label' => 'Motivo', 'class' => 'w-1/4'], ['label' => 'Perguntas', 'align' => 'right'], ['label' => 'Exemplos'], ['label' => 'Última'], ['label' => '', 'class' => 'w-px']]">
                    @foreach ($groups as $group)
                        @php
                            [$reasonLabel, $reasonHint] = \App\Livewire\Admin\Knowledge\UnansweredQuestions\Index::REASONS[$group->review_reason] ?? [$group->review_reason, ''];
                            $key = $group->medicine_id.'|'.$group->medicine_name.'|'.$group->review_reason;
                        @endphp
                        <tr wire:key="uq-{{ md5($key) }}">
                            <td class="min-w-40">
                                @if ($group->medicine_id)<a class="font-semibold text-brand-800 hover:text-brand-700 hover:underline" href="{{ route('admin.knowledge.catalog.index', ['q' => $group->medicine_name]) }}" wire:navigate>{{ $group->medicine_name }}</a>@else<span class="font-semibold text-slate-900">{{ $group->medicine_name }}</span>@endif
                                <span class="block text-xs font-normal text-slate-600">{{ (int) $group->patients === 1 ? '1 paciente' : $group->patients.' pacientes' }}</span>
                            </td>
                            <td class="min-w-56">
                                <x-knowledge-admin.badge :tone="$group->review_reason === 'no_evidence' ? 'warning' : 'danger'">{{ $reasonLabel }}</x-knowledge-admin.badge>
                                @if ($isPending)<span class="mt-1 block text-xs leading-relaxed text-slate-700">{{ $reasonHint }}</span>@endif
                            </td>
                            <td class="whitespace-nowrap text-right font-semibold tabular-nums text-slate-900">{{ $group->total }}</td>
                            <td class="min-w-56 text-sm">
                                @foreach ($examples[$key] ?? [] as $example)
                                    <a class="block py-0.5 text-slate-800 hover:text-brand-700 hover:underline" href="{{ route('admin.analytics.questions.show', $example->uuid) }}" wire:navigate>“{{ $example->question }}”</a>
                                @endforeach
                            </td>
                            <td class="whitespace-nowrap text-slate-700">{{ \Illuminate\Support\Carbon::parse($group->last_at)->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td>
                            <td>
                                @if ($isPending)
                                    <x-knowledge-admin.row-actions>
                                        <x-knowledge-admin.button variant="secondary" size="sm" wire:click="resolver({{ $group->medicine_id ?? 'null' }}, @js($group->medicine_name), @js($group->review_reason))" loading-target="resolver" loading="Salvando…">Resolvida</x-knowledge-admin.button>
                                        <x-knowledge-admin.button variant="quiet" size="sm" wire:click="descartar({{ $group->medicine_id ?? 'null' }}, @js($group->medicine_name), @js($group->review_reason))" loading-target="descartar" loading="Salvando…">Descartar</x-knowledge-admin.button>
                                    </x-knowledge-admin.row-actions>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-knowledge-admin.data-table>
            </div>
            {{ $groups->links() }}
        @endif
    </div>
</x-knowledge-admin.shell>
