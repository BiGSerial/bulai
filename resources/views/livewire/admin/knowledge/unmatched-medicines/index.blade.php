<x-knowledge-admin.shell title="Remédios não encontrados" subtitle="Pacientes cadastraram estes remédios pelo nome e o catálogo ANVISA não os reconheceu. Ligue cada um ao medicamento certo para que ganhe bula simples e entre no cruzamento de interações.">
    <x-knowledge-admin.toolbar :count="$groups->total().' '.($groups->total() === 1 ? 'nome' : 'nomes').(trim($search) !== '' ? ' para “'.$search.'”' : '').' · '.$totals['open'].' '.($totals['open'] === 1 ? 'pendência aberta' : 'pendências abertas').' no total'">
        <x-knowledge-admin.search compact label="Buscar" wire:model.live.debounce.300ms="search" placeholder="Nome como o paciente escreveu" wrapper-class="w-full sm:w-80" />
        <x-knowledge-admin.select compact label="Situação" wire:model.live="status" wrapper-class="w-full sm:w-44"><option value="open">Abertas</option><option value="resolved">Ligadas ao catálogo</option><option value="dismissed">Descartadas</option></x-knowledge-admin.select>
    </x-knowledge-admin.toolbar>

    @if ($groups->isEmpty())
        <x-knowledge-admin.empty-state>
            @if ($isOpen && trim($search) === '')Nenhuma pendência: todos os remédios cadastrados pelos pacientes estão ligados ao catálogo.@else Nenhum remédio encontrado com estes filtros.@endif
        </x-knowledge-admin.empty-state>
    @else
        <div wire:loading.class="opacity-60" wire:target="search,status">
            <x-knowledge-admin.data-table :columns="[['label' => 'Nome escrito pelo paciente'], ['label' => 'Pacientes', 'align' => 'right'], ['label' => 'Posologias de exemplo'], ['label' => 'Desde'], ['label' => $isOpen ? 'Situação' : 'Resultado'], ['label' => '', 'class' => 'w-px']]">
                @foreach ($groups as $group)
                    @php
                        $patients = (int) $group->patients;
                        $outcome = $outcomes[$group->normalized_name] ?? null;
                        $expanded = $isOpen && $openName === $group->normalized_name;
                    @endphp
                    <tr wire:key="group-{{ md5($group->normalized_name) }}" class="{{ $expanded ? 'bg-brand-50/60' : 'hover:bg-brand-50/40' }}">
                        <td class="max-w-xs px-3 py-2"><span class="block truncate font-semibold text-slate-900" title="{{ $group->name_as_written }}">{{ $group->name_as_written }}</span></td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">{{ $patients === 1 ? '1 paciente' : $patients.' pacientes' }}</td>
                        <td class="max-w-xs px-3 py-2 text-slate-700"><span class="block truncate" title="{{ $examples[$group->normalized_name] ?? '' }}">{{ filled($examples[$group->normalized_name] ?? null) ? $examples[$group->normalized_name] : 'sem posologia informada' }}</span></td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ \Illuminate\Support\Carbon::parse($group->first_at)->timezone(config('app.timezone'))->format('d/m/Y') }}</td>
                        <td class="whitespace-nowrap px-3 py-2">
                            @if ($isOpen)
                                <x-knowledge-admin.badge tone="warning">Sem catálogo</x-knowledge-admin.badge>
                            @elseif ($outcome?->status === \App\Models\UnmatchedMedicineReport::STATUS_RESOLVED)
                                <x-knowledge-admin.badge tone="success">Ligado a {{ $outcome->resolvedMedicine?->product_name ?? 'medicamento removido do catálogo' }}</x-knowledge-admin.badge>
                                <span class="ml-1 text-xs text-slate-500">{{ $outcome->resolver?->name ?? 'Sistema' }} · {{ $outcome->resolved_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span>
                            @else
                                <x-knowledge-admin.badge>Descartado</x-knowledge-admin.badge>
                                <span class="ml-1 text-xs text-slate-500">{{ $outcome?->resolver?->name ?? 'Sistema' }} · {{ $outcome?->resolved_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span>
                            @endif
                        </td>
                        <td class="px-2 py-1">
                            @if ($isOpen)
                                <x-knowledge-admin.row-actions>
                                    <x-knowledge-admin.button :variant="$expanded ? 'secondary' : 'primary'" size="sm" wire:click="abrir('{{ $group->normalized_name }}')" loading-target="abrir" aria-expanded="{{ $expanded ? 'true' : 'false' }}">{{ $expanded ? 'Fechar' : 'Ligar ao catálogo' }}</x-knowledge-admin.button>
                                    <x-knowledge-admin.confirm-modal action="descartar('{{ $group->normalized_name }}')" tone="danger" title="Descartar esta pendência?" :message="'“'.$group->name_as_written.'” continuará no tratamento '.($patients === 1 ? 'do paciente' : 'dos '.$patients.' pacientes').' só pelo nome, sem bula simples nem cruzamento de interações. Você pode ligar ao catálogo depois, pela situação “Descartadas”.'" confirm-label="Descartar" loading="Descartando…">
                                        <x-knowledge-admin.button variant="quiet" size="sm">Descartar</x-knowledge-admin.button>
                                    </x-knowledge-admin.confirm-modal>
                                </x-knowledge-admin.row-actions>
                            @endif
                        </td>
                    </tr>

                    @if ($expanded)
                        <tr wire:key="panel-{{ md5($group->normalized_name) }}" class="bg-brand-50/40">
                            <td colspan="6" class="px-4 py-3">
                                <div class="grid gap-3" aria-label="Ligar “{{ $group->name_as_written }}” ao catálogo">
                                    <p class="text-sm text-slate-700">Busque o medicamento no catálogo ANVISA. Ao ligar, {{ $patients === 1 ? 'a pendência deste paciente é resolvida e ele é avisado' : 'as pendências dos '.$patients.' pacientes são resolvidas e todos são avisados' }}.</p>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <x-knowledge-admin.search label="Medicamento no catálogo" wire:model.live.debounce.300ms="catalogSearch" placeholder="Nome comercial ou princípio ativo" wrapper-class="w-full" />
                                        <x-knowledge-admin.input label="Observação" wire:model="note" placeholder="Ex.: mesmo produto, grafia diferente" maxlength="255" optional wrapper-class="w-full" />
                                    </div>
                                    <div wire:loading wire:target="catalogSearch" class="text-xs text-slate-500">Buscando no catálogo…</div>
                                    @if (strlen(trim($catalogSearch)) < 3)
                                        <p class="text-sm text-slate-600">Digite pelo menos 3 letras para buscar.</p>
                                    @elseif ($candidates === [])
                                        <p class="text-sm text-slate-600">Nenhum medicamento do catálogo corresponde a “{{ $catalogSearch }}”. Tente só o princípio ativo ou descarte a pendência.</p>
                                    @else
                                        @if ($candidates[0]['fuzzy'] ?? false)<p class="text-sm font-medium text-slate-700">Nada começa com “{{ $catalogSearch }}”. Você quis dizer…?</p>@endif
                                        <x-knowledge-admin.data-table :columns="[['label' => 'Medicamento'], ['label' => 'Princípio ativo'], ['label' => 'Detentor'], ['label' => 'Catálogo'], ['label' => '', 'class' => 'w-px']]">
                                            @foreach ($candidates as $candidate)
                                                <tr wire:key="cand-{{ $candidate['id'] }}" class="hover:bg-brand-50/40">
                                                    <td class="px-3 py-2 font-semibold text-slate-900">{{ $candidate['name'] }}</td>
                                                    <td class="max-w-xs px-3 py-2 text-slate-700"><span class="block truncate" title="{{ $candidate['ingredients'] }}">{{ $candidate['ingredients'] }}</span></td>
                                                    <td class="max-w-xs px-3 py-2 text-slate-700"><span class="block truncate" title="{{ $candidate['holder'] }}">{{ $candidate['holder'] }}</span></td>
                                                    <td class="whitespace-nowrap px-3 py-2">@if (! $candidate['identified'])<x-knowledge-admin.badge tone="warning">Princípio ativo não identificado</x-knowledge-admin.badge>@elseif ($candidate['inactive'])<x-knowledge-admin.badge>Registro inativo</x-knowledge-admin.badge>@else<x-knowledge-admin.badge tone="success">Identificado</x-knowledge-admin.badge>@endif</td>
                                                    <td class="px-2 py-1"><x-knowledge-admin.row-actions><x-knowledge-admin.button size="sm" wire:click="ligar({{ $candidate['id'] }})" loading-target="ligar" loading="Ligando…">Ligar</x-knowledge-admin.button></x-knowledge-admin.row-actions></td>
                                                </tr>
                                            @endforeach
                                        </x-knowledge-admin.data-table>
                                    @endif
                                    <div><x-knowledge-admin.button variant="quiet" size="sm" wire:click="fechar">Fechar</x-knowledge-admin.button></div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </x-knowledge-admin.data-table>
        </div>
    @endif
    {{ $groups->links() }}
</x-knowledge-admin.shell>
