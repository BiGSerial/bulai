<x-knowledge-admin.shell title="Perguntas sem resposta" subtitle="O Perguntar não conseguiu responder estas perguntas mesmo depois de esgotar as fontes (busca por trechos e leitura da bula inteira). Adquira a fonte que falta e marque como resolvida.">
    <x-knowledge-admin.toolbar :count="$groups->total().' '.($groups->total() === 1 ? 'remédio' : 'remédios').' · '.$totals['pending'].' '.($totals['pending'] === 1 ? 'pergunta pendente' : 'perguntas pendentes').' no total'">
        <x-knowledge-admin.search compact label="Buscar" wire:model.live.debounce.300ms="search" placeholder="Nome do remédio" wrapper-class="w-full sm:w-80" />
        <x-knowledge-admin.select compact label="Situação" wire:model.live="status" wrapper-class="w-full sm:w-44"><option value="pending">Pendentes</option><option value="resolved">Resolvidas</option><option value="dismissed">Descartadas</option></x-knowledge-admin.select>
    </x-knowledge-admin.toolbar>
    @if ($feedback)<x-knowledge-admin.alert>{{ $feedback }}</x-knowledge-admin.alert>@endif

    @if ($groups->isEmpty())
        <x-knowledge-admin.empty-state>
            @if ($isPending && trim($search) === '')Nenhuma pendência: todas as perguntas dos pacientes encontraram resposta nas bulas cadastradas.@else Nenhuma pergunta encontrada com estes filtros.@endif
        </x-knowledge-admin.empty-state>
    @else
        <div wire:loading.class="opacity-60" wire:target="search,status">
            <x-knowledge-admin.data-table :columns="[['label' => 'Remédio'], ['label' => 'Motivo'], ['label' => 'Perguntas', 'align' => 'right'], ['label' => 'Exemplos'], ['label' => 'Última'], ['label' => '', 'class' => 'w-px']]">
                @foreach ($groups as $group)
                    @php
                        [$reasonLabel, $reasonHint] = \App\Livewire\Admin\Knowledge\UnansweredQuestions\Index::REASONS[$group->review_reason] ?? [$group->review_reason, ''];
                        $key = $group->medicine_id.'|'.$group->medicine_name.'|'.$group->review_reason;
                    @endphp
                    <tr wire:key="uq-{{ md5($key) }}" class="hover:bg-brand-50/40">
                        <td class="max-w-xs px-3 py-2">
                            @if ($group->medicine_id)<a class="block truncate font-semibold text-slate-900 hover:text-brand-700 hover:underline" href="{{ route('admin.knowledge.catalog.index', ['q' => $group->medicine_name]) }}" wire:navigate title="{{ $group->medicine_name }}">{{ $group->medicine_name }}</a>@else<span class="block truncate font-semibold text-slate-900" title="{{ $group->medicine_name }}">{{ $group->medicine_name }}</span>@endif
                            <span class="block text-xs text-slate-500">{{ (int) $group->patients === 1 ? '1 paciente' : $group->patients.' pacientes' }}</span>
                        </td>
                        <td class="max-w-sm px-3 py-2"><x-knowledge-admin.badge :tone="$group->review_reason === 'no_evidence' ? 'warning' : 'danger'">{{ $reasonLabel }}</x-knowledge-admin.badge>@if ($isPending)<span class="mt-1 block text-xs text-slate-600">{{ $reasonHint }}</span>@endif</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">{{ $group->total }}</td>
                        <td class="max-w-md px-3 py-2 text-sm text-slate-700">
                            @foreach ($examples[$key] ?? [] as $example)
                                <a class="block truncate hover:underline" href="{{ route('admin.analytics.questions.show', $example->uuid) }}" wire:navigate title="{{ $example->question }}">“{{ $example->question }}”</a>
                            @endforeach
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ \Illuminate\Support\Carbon::parse($group->last_at)->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</td>
                        <td class="px-2 py-1">
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
</x-knowledge-admin.shell>
