@php
    use App\Services\Treatment\TreatmentScheduleService as T;
    // Estado em uma palavra, sempre com cor E texto; a bolinha da linha do tempo repete a cor.
    $estado = fn (string $status): array => match ($status) {
        T::DOSE_TAKEN => ['Tomei', 'success', 'bg-emerald-600 ring-emerald-100'],
        T::DOSE_SKIPPED => ['Pulei', 'neutral', 'bg-slate-400 ring-slate-100'],
        T::DOSE_LATE => ['Atrasado', 'danger', 'bg-rose-600 ring-rose-100'],
        T::DOSE_DUE => ['Agora', 'accent', 'bg-accent-500 ring-accent-100 animate-pulse'],
        default => ['A tomar', 'brand', 'bg-white ring-slate-300'],
    };
@endphp
<x-consulta.shell :title="$titulo" :back="route('consulta.inicio')" :conta="$vinculo !== null" :cuidando="$vinculo">
    @unless ($consentido)
        <x-consulta.cartao tone="warning">
            <p class="text-xl font-semibold leading-tight">Para programar seus remédios, autorize guardar seus dados de saúde.</p>
            <x-consulta.botao :href="route('consulta.perfil')">Abrir Meus remédios</x-consulta.botao>
        </x-consulta.cartao>
    @else
        {{-- Seletor de dia em pílulas + data por extenso. --}}
        <x-consulta.segmentos :itens="['-1' => 'Ontem', '0' => 'Hoje', '1' => 'Amanhã']" acao="irPara" :ativo="(string) $dia" label="Dia" prefixo="dia" />
        <p class="-mt-2 px-1 text-center text-base text-slate-700">{{ \Illuminate\Support\Str::ucfirst($data->translatedFormat('l, j \d\e F')) }}</p>
        @if ($vinculo !== null && ! $podeMarcar)
            <p class="flex items-start gap-2 rounded-2xl bg-slate-100 px-4 py-3 text-base text-slate-800" role="note"><x-consulta.icone name="info" plain class="mt-0.5 size-5 shrink-0" /><span class="min-w-0 break-words">Você pode ver os horários, mas {{ \App\Models\CaregiverLink::firstName($vinculo->patient) }} não liberou marcar doses.</span></p>
        @endif

        {{-- Só a lista se atualiza sozinha (estados "Agora"/"Atrasado" mudam com a hora). --}}
        <div class="grid gap-4" wire:poll.60s aria-live="polite">
            @if ($total > 0)
                <div class="flex items-center gap-4 rounded-2xl bg-white px-4 py-3 ring-1 ring-slate-200" role="status">
                    <x-consulta.anel-progresso :feitos="$tomadas" :total="$total" rotulo="tomadas" size="sm" />
                    <div class="min-w-0">
                        <p class="break-words text-xl font-semibold leading-tight text-slate-900">{{ $feitos }} de {{ $total }} {{ $total === 1 ? 'horário marcado' : 'horários marcados' }}.</p>
                        <p class="break-words text-base text-slate-600">{{ $tomadas === $total ? 'Tudo em dia. Bom trabalho!' : ($feitos < $total ? ($total - $feitos).' ainda sem marcação.' : 'Dia concluído.') }}</p>
                    </div>
                </div>
                @if ($atrasadas > 0)
                    <div role="alert" class="flex items-center gap-3 rounded-2xl border-2 border-rose-300 bg-rose-50 px-4 py-3 text-rose-900">
                        <x-consulta.icone name="alerta" tone="danger" />
                        <p class="min-w-0 break-words text-lg font-semibold leading-snug">{{ $atrasadas === 1 ? 'Um horário passou sem marcação.' : $atrasadas.' horários passaram sem marcação.' }} Marque “Tomei” ou “Pular”.</p>
                    </div>
                @endif
                @if ($temDinamico)
                    <p class="flex items-start gap-2 px-1 text-base text-slate-600" role="note"><x-consulta.icone name="relogio" plain class="mt-0.5 size-5 shrink-0" /><span class="min-w-0 break-words">Remédios “de tantas em tantas horas” se ajustam ao horário em que você marca “Tomei”: a próxima dose é contada a partir dali.</span></p>
                @endif
            @endif

            {{-- Linha do tempo: horário à esquerda, bolinha no trilho e o cartão do remédio à direita, alinhados pela primeira linha. --}}
            @forelse ($periodos as $chave => [$rotulo, $icone, $doses])
                <section class="grid gap-1" aria-labelledby="per-{{ $chave }}">
                    <h2 id="per-{{ $chave }}" class="flex items-center gap-2 px-1 pb-1 text-lg font-semibold text-brand-900"><x-consulta.icone :name="$icone" size="sm" />{{ $rotulo }}</h2>
                    <ol class="grid">
                        @foreach ($doses as $dose)
                            @php [$texto, $tom, $bolinha] = $estado($dose['status']); $iso = $dose['scheduled_for']->utc()->toIso8601String(); $marcado = in_array($dose['status'], [T::DOSE_TAKEN, T::DOSE_SKIPPED], true); $alvo = $dose['medication']->id.", '".$iso."'"; $tomouAs = $dose['taken_at']?->format('H:i'); @endphp
                            <li wire:key="d-{{ $dose['medication']->id }}-{{ $dose['time'] }}" class="grid grid-cols-[3.5rem_1.25rem_minmax(0,1fr)] gap-x-2 sm:grid-cols-[4rem_1.5rem_minmax(0,1fr)]">
                                <p class="pt-3 text-right text-lg font-bold leading-7 tabular-nums text-brand-900 {{ $marcado ? 'opacity-60' : '' }}">{{ $dose['time'] }}</p>
                                <span class="relative flex justify-center {{ $loop->last ? '' : 'before:absolute before:inset-y-0 before:w-0.5 before:bg-slate-200' }} {{ $loop->first ? '' : 'after:absolute after:top-0 after:h-5 after:w-0.5 after:bg-slate-200' }}" aria-hidden="true">
                                    <span class="relative z-10 mt-[1.2rem] size-3.5 rounded-full ring-4 {{ $bolinha }}"></span>
                                </span>
                                <article class="mb-3 grid min-w-0 gap-2 rounded-2xl bg-white px-3 py-3 ring-1 transition sm:px-4 {{ $dose['status'] === T::DOSE_LATE ? 'ring-rose-300' : ($dose['status'] === T::DOSE_DUE ? 'ring-2 ring-accent-400 shadow-md' : 'ring-slate-200') }}" aria-label="{{ $dose['time'] }}, {{ $dose['medication']->displayName() }}: {{ $texto }}">
                                    <div class="flex min-w-0 items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="break-words text-lg font-semibold leading-7 text-slate-900 {{ $dose['status'] === T::DOSE_SKIPPED ? 'line-through decoration-2 opacity-70' : '' }}">{{ $dose['medication']->displayName() }}</p>
                                            <p class="break-words text-base leading-snug text-slate-700">{{ collect([$dose['medication']->dosage ?: 'Dose não informada', $dose['dynamic'] ? $dose['medication']->intervalLabel() : null])->filter()->join(' · ') }}</p>
                                        </div>
                                        <x-consulta.selo :tone="$tom" class="shrink-0 !px-2.5 !text-sm">{{ $dose['status'] === T::DOSE_TAKEN && $tomouAs !== null ? 'Tomei '.$tomouAs : $texto }}</x-consulta.selo>
                                    </div>
                                    @if ($dose['ajustado'] ?? null)
                                        <p class="break-words text-sm text-slate-600"><span class="font-semibold">Ajustado:</span> contado a partir das {{ $dose['ajustado'] }}, quando você tomou a anterior</p>
                                    @endif
                                    @if (! $podeMarcar)
                                    @elseif ($marcado)
                                        <button type="button" class="inline-flex min-h-10 items-center gap-1.5 justify-self-start rounded-lg px-2 text-base font-semibold text-brand-800 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="desfazer({{ $alvo }})" wire:loading.attr="disabled"><x-consulta.icone name="desfazer" plain class="size-5" />Desfazer</button>
                                    @else
                                        <div class="flex gap-2">
                                            <x-consulta.botao variant="success" size="sm" class="min-w-0 flex-[2]" wire:click="tomei({{ $alvo }})" wire:loading.attr="disabled" wire:target="tomei({{ $alvo }})"><span wire:loading.remove wire:target="tomei({{ $alvo }})" class="inline-flex items-center gap-1.5"><x-consulta.icone name="check" plain class="size-6" />Tomei</span><span wire:loading wire:target="tomei({{ $alvo }})">Anotando…</span></x-consulta.botao>
                                            <x-consulta.botao variant="quiet" size="sm" class="min-w-0 flex-1 ring-1 ring-slate-300" wire:click="pular({{ $alvo }})" wire:loading.attr="disabled">Pular</x-consulta.botao>
                                        </div>
                                    @endif
                                </article>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @empty
                @if ($vinculo !== null)
                <x-consulta.vazio icon="relogio" titulo="Nenhum horário programado para este dia." :texto="'Os remédios com horário de '.\App\Models\CaregiverLink::firstName($vinculo->patient).' aparecem aqui.'">
                    <x-consulta.botao variant="soft" size="md" :href="route('consulta.cuidando.remedios', $vinculo)">Ver os remédios de {{ \App\Models\CaregiverLink::firstName($vinculo->patient) }}</x-consulta.botao>
                </x-consulta.vazio>
                @else
                <x-consulta.vazio icon="relogio" :titulo="$temRemedios ? 'Nenhum horário programado para este dia.' : 'Você ainda não tem remédios programados.'" :texto="$temRemedios ? 'Toque em “Informar dose e horários” em Meus remédios para definir os horários.' : 'Fotografe sua receita ou cadastre os remédios à mão.'">
                    <x-consulta.botao variant="accent" :href="route('consulta.perfil')"><x-consulta.icone name="mais" plain class="size-6" />Adicionar remédio</x-consulta.botao>
                    <x-consulta.botao variant="secondary" size="md" :href="route('consulta.receita.nova')"><x-consulta.icone name="camera" plain class="size-6" />Fotografar receita</x-consulta.botao>
                    <x-consulta.botao variant="quiet" size="sm" :href="route('consulta.perfil')">Meus remédios</x-consulta.botao>
                </x-consulta.vazio>
                @endif
            @endforelse
        </div>

        @if ($quandoPrecisar->isNotEmpty())
            <section class="grid gap-3" aria-labelledby="sos-titulo">
                <h2 id="sos-titulo" class="flex items-center gap-2 px-1 text-xl font-semibold text-brand-900"><x-consulta.icone name="pilula" tone="neutral" size="sm" />Só quando precisar</h2>
                <ul class="grid gap-2">
                    @foreach ($quandoPrecisar as $item)
                        <li wire:key="sos-{{ $item->id }}" class="flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-2xl bg-white px-4 py-3 ring-1 ring-slate-200">
                            <span class="min-w-0"><span class="block break-words text-lg font-semibold text-slate-900">{{ $item->displayName() }}</span><span class="block break-words text-base text-slate-600">{{ $item->dosage ?: 'Sem horário fixo' }}</span></span>
                            @if ($item->isMatched())<a href="{{ route('consulta.bula', $item->medicine_id) }}" wire:navigate class="inline-flex min-h-11 items-center text-base font-semibold text-brand-800 underline-offset-2 hover:underline">Ver a bula simples ›</a>@endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($vinculo !== null)
            <x-consulta.botao variant="soft" size="md" :href="route('consulta.cuidando.remedios', $vinculo)"><x-consulta.icone name="remedios" plain class="size-6" />Remédios de {{ \App\Models\CaregiverLink::firstName($vinculo->patient) }}</x-consulta.botao>
        @elseif ($total > 0)
            <x-consulta.botao variant="soft" size="md" :href="route('consulta.perfil')"><x-consulta.icone name="remedios" plain class="size-6" />Meus remédios, receitas e alergias</x-consulta.botao>
        @endif
    @endunless
</x-consulta.shell>
