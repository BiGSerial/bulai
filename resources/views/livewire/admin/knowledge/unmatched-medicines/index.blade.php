<x-knowledge-admin.shell title="Remédios não encontrados" subtitle="Pacientes cadastraram estes remédios pelo nome e o catálogo ANVISA não os reconheceu. Ligue cada um ao medicamento certo para que ganhe bula simples e entre no cruzamento de interações.">
    <x-knowledge-admin.ops-filters :count="$groups->total().' '.($groups->total() === 1 ? 'nome' : 'nomes').(trim($search) !== '' ? ' para “'.$search.'”' : '').' · '.$totals['open'].' '.($totals['open'] === 1 ? 'pendência aberta' : 'pendências abertas').' no total'">
        <x-knowledge-admin.search label="Buscar" wire:model.live.debounce.300ms="search" placeholder="Nome como o paciente escreveu" wrapper-class="w-full sm:max-w-xs" />
        <x-knowledge-admin.select label="Situação" wire:model.live="status" wrapper-class="w-full sm:w-44"><option value="open">Abertas</option><option value="resolved">Ligadas ao catálogo</option><option value="dismissed">Descartadas</option></x-knowledge-admin.select>
    </x-knowledge-admin.ops-filters>

    @if ($groups->isEmpty())
        <x-knowledge-admin.empty-state>
            @if ($isOpen && trim($search) === '')Nenhuma pendência: todos os remédios cadastrados pelos pacientes estão ligados ao catálogo.@else Nenhum remédio encontrado com estes filtros.@endif
        </x-knowledge-admin.empty-state>
    @else
        <ul class="grid gap-2" wire:loading.class="opacity-60" wire:target="search,status">
            @foreach ($groups as $group)
                @php
                    $patients = (int) $group->patients;
                    $support = ($patients === 1 ? '1 paciente' : $patients.' pacientes').' · desde '.\Illuminate\Support\Carbon::parse($group->first_at)->timezone(config('app.timezone'))->format('d/m/Y').(filled($examples[$group->normalized_name] ?? null) ? ' · '.$examples[$group->normalized_name] : ' · sem posologia informada');
                    $outcome = $outcomes[$group->normalized_name] ?? null;
                @endphp
                <x-knowledge-admin.ops-row :title="$group->name_as_written" :support="$support" wire:key="group-{{ md5($group->normalized_name) }}">
                    @if ($isOpen)
                        <x-knowledge-admin.badge tone="warning">Sem catálogo</x-knowledge-admin.badge>
                        <x-knowledge-admin.button wire:click="abrir('{{ $group->normalized_name }}')" loading-target="abrir" aria-expanded="{{ $openName === $group->normalized_name ? 'true' : 'false' }}">{{ $openName === $group->normalized_name ? 'Fechar' : 'Ligar ao catálogo' }}</x-knowledge-admin.button>
                        <x-knowledge-admin.confirm-modal action="descartar('{{ $group->normalized_name }}')" tone="danger" title="Descartar esta pendência?" :message="'“'.$group->name_as_written.'” continuará no tratamento '.($patients === 1 ? 'do paciente' : 'dos '.$patients.' pacientes').' só pelo nome, sem bula simples nem cruzamento de interações. Você pode ligar ao catálogo depois, pela situação “Descartadas”.'" confirm-label="Descartar" loading="Descartando…">
                            <x-knowledge-admin.button variant="quiet">Descartar</x-knowledge-admin.button>
                        </x-knowledge-admin.confirm-modal>
                    @elseif ($outcome?->status === \App\Models\UnmatchedMedicineReport::STATUS_RESOLVED)
                        <x-knowledge-admin.badge tone="success">Ligado a {{ $outcome->resolvedMedicine?->product_name ?? 'medicamento removido do catálogo' }}</x-knowledge-admin.badge>
                        <span class="text-xs text-slate-500">{{ $outcome->resolver?->name ?? 'Sistema' }} · {{ $outcome->resolved_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span>
                    @else
                        <x-knowledge-admin.badge>Descartado</x-knowledge-admin.badge>
                        <span class="text-xs text-slate-500">{{ $outcome?->resolver?->name ?? 'Sistema' }} · {{ $outcome?->resolved_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span>
                    @endif
                </x-knowledge-admin.ops-row>

                @if ($isOpen && $openName === $group->normalized_name)
                    <li wire:key="panel-{{ md5($group->normalized_name) }}">
                        <x-knowledge-admin.card class="grid gap-3 border-brand-100 bg-brand-50/40" aria-label="Ligar “{{ $group->name_as_written }}” ao catálogo">
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
                                <ul class="grid gap-2" aria-label="Medicamentos do catálogo">
                                    @foreach ($candidates as $candidate)
                                        <x-knowledge-admin.ops-row :title="$candidate['name']" :support="\Illuminate\Support\Str::limit($candidate['ingredients'], 70).' · '.$candidate['holder']" wire:key="cand-{{ $candidate['id'] }}">
                                            @if (! $candidate['identified'])<x-knowledge-admin.badge tone="warning">Princípio ativo não identificado</x-knowledge-admin.badge>@elseif ($candidate['inactive'])<x-knowledge-admin.badge>Registro inativo</x-knowledge-admin.badge>@else<x-knowledge-admin.badge tone="success">Identificado</x-knowledge-admin.badge>@endif
                                            <x-knowledge-admin.button wire:click="ligar({{ $candidate['id'] }})" loading-target="ligar" loading="Ligando…">Ligar</x-knowledge-admin.button>
                                        </x-knowledge-admin.ops-row>
                                    @endforeach
                                </ul>
                            @endif
                            <div><x-knowledge-admin.button variant="secondary" wire:click="fechar">Fechar</x-knowledge-admin.button></div>
                        </x-knowledge-admin.card>
                    </li>
                @endif
            @endforeach
        </ul>
    @endif
    {{ $groups->links() }}
</x-knowledge-admin.shell>
