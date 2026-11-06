@php
    use App\Services\Treatment\TreatmentScheduleService as T;
    // Estado em uma palavra, sempre com cor E texto; a bolinha da linha do tempo repete a cor.
    $estado = fn (string $status): array => match ($status) {
        T::DOSE_TAKEN => ['Tomei', 'success', 'bg-emerald-600 ring-emerald-200'],
        T::DOSE_SKIPPED => ['Pulei', 'neutral', 'bg-slate-400 ring-slate-200'],
        T::DOSE_LATE => ['Atrasado', 'danger', 'bg-rose-600 ring-rose-200'],
        T::DOSE_DUE => ['Agora', 'accent', 'bg-accent-500 ring-accent-200 animate-pulse'],
        default => ['A tomar', 'brand', 'bg-slate-300 ring-slate-100'],
    };
@endphp
<x-consulta.shell :title="$titulo" :back="route('consulta.inicio')">
    @unless ($consentido)
        <x-consulta.cartao tone="warning">
            <p class="text-xl font-semibold leading-tight">Para programar seus remédios, autorize guardar seus dados de saúde.</p>
            <x-consulta.botao :href="route('consulta.perfil')">Abrir Meus remédios</x-consulta.botao>
        </x-consulta.cartao>
    @else
        {{-- Seletor de dia em pílulas + data por extenso. --}}
        <x-consulta.segmentos :itens="['-1' => 'Ontem', '0' => 'Hoje', '1' => 'Amanhã']" acao="irPara" :ativo="(string) $dia" label="Dia" prefixo="dia" />
        <p class="-mt-2 px-1 text-center text-lg text-slate-700">{{ mb_convert_case($data->translatedFormat('l, j \d\e F'), MB_CASE_TITLE, 'UTF-8') }}</p>

        {{-- Só a lista se atualiza sozinha (estados "Agora"/"Atrasado" mudam com a hora). --}}
        <div class="grid gap-4" wire:poll.60s aria-live="polite">
            @if ($total > 0)
                <div class="flex items-center gap-4 rounded-2xl bg-white p-4 ring-1 ring-slate-200" role="status">
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
            @endif

            @forelse ($periodos as $chave => [$rotulo, $icone, $doses])
                <section class="grid gap-3" aria-labelledby="per-{{ $chave }}">
                    <h2 id="per-{{ $chave }}" class="flex items-center gap-2 px-1 text-xl font-semibold text-brand-900"><x-consulta.icone :name="$icone" size="sm" />{{ $rotulo }}</h2>
                    <ol class="relative grid gap-3 border-l-4 border-slate-200 pl-5 ml-3">
                        @foreach ($doses as $dose)
                            @php [$texto, $tom, $bolinha] = $estado($dose['status']); $iso = $dose['scheduled_for']->utc()->toIso8601String(); $marcado = in_array($dose['status'], [T::DOSE_TAKEN, T::DOSE_SKIPPED], true); $alvo = $dose['medication']->id.", '".$iso."'"; @endphp
                            <li wire:key="d-{{ $dose['medication']->id }}-{{ $dose['time'] }}" class="relative">
                                <span class="absolute -left-[2.05rem] top-6 size-5 rounded-full ring-4 {{ $bolinha }}" aria-hidden="true"></span>
                                <article class="grid min-w-0 gap-3 rounded-2xl bg-white p-4 ring-1 transition {{ $dose['status'] === T::DOSE_LATE ? 'ring-rose-300' : ($dose['status'] === T::DOSE_DUE ? 'ring-2 ring-accent-400 shadow-md' : 'ring-slate-200') }} {{ $dose['status'] === T::DOSE_SKIPPED ? 'opacity-75' : '' }}" aria-label="{{ $dose['time'] }}, {{ $dose['medication']->displayName() }}: {{ $texto }}">
                                    <div class="flex min-w-0 items-start gap-3">
                                        <p class="shrink-0 rounded-xl bg-brand-50 px-3 py-2 text-2xl font-bold leading-none tabular-nums text-brand-900">{{ $dose['time'] }}</p>
                                        <div class="min-w-0 flex-1">
                                            <p class="break-words text-xl font-semibold leading-snug text-slate-900 {{ $dose['status'] === T::DOSE_SKIPPED ? 'line-through decoration-2' : '' }}">{{ $dose['medication']->displayName() }}</p>
                                            <p class="break-words text-slate-700">{{ $dose['medication']->dosage ?: 'Dose não informada' }}</p>
                                        </div>
                                        <x-consulta.selo :tone="$tom" class="shrink-0">{{ $texto }}</x-consulta.selo>
                                    </div>
                                    @if ($marcado)
                                        <x-consulta.botao variant="quiet" size="sm" class="justify-self-start !w-auto" wire:click="desfazer({{ $alvo }})" wire:loading.attr="disabled"><x-consulta.icone name="desfazer" plain class="size-5" />Desfazer</x-consulta.botao>
                                    @else
                                        <div class="flex flex-wrap gap-2">
                                            <x-consulta.botao variant="success" class="min-w-0 flex-1 basis-40" wire:click="tomei({{ $alvo }})" wire:loading.attr="disabled" wire:target="tomei({{ $alvo }})"><span wire:loading.remove wire:target="tomei({{ $alvo }})" class="inline-flex items-center gap-2"><x-consulta.icone name="check" plain class="size-7" />Tomei</span><span wire:loading wire:target="tomei({{ $alvo }})">Anotando…</span></x-consulta.botao>
                                            <x-consulta.botao variant="secondary" size="md" class="min-w-0 flex-1 basis-32 border-slate-300 text-slate-800" wire:click="pular({{ $alvo }})" wire:loading.attr="disabled">Pular</x-consulta.botao>
                                        </div>
                                    @endif
                                </article>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @empty
                <x-consulta.vazio icon="relogio" :titulo="$temRemedios ? 'Nenhum horário programado para este dia.' : 'Você ainda não tem remédios programados.'" :texto="$temRemedios ? 'Toque em “Informar dose e horários” em Meus remédios para definir os horários.' : 'Fotografe sua receita ou cadastre os remédios à mão.'">
                    <x-consulta.botao variant="accent" :href="route('consulta.perfil')"><x-consulta.icone name="mais" plain class="size-6" />Adicionar remédio</x-consulta.botao>
                    <x-consulta.botao variant="secondary" size="md" :href="route('consulta.receita.nova')"><x-consulta.icone name="camera" plain class="size-6" />Fotografar receita</x-consulta.botao>
                    <x-consulta.botao variant="quiet" size="sm" :href="route('consulta.perfil')">Meus remédios</x-consulta.botao>
                </x-consulta.vazio>
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

        @if ($total > 0)
            <x-consulta.botao variant="soft" size="md" :href="route('consulta.perfil')"><x-consulta.icone name="remedios" plain class="size-6" />Meus remédios, receitas e alergias</x-consulta.botao>
        @endif
    @endunless
</x-consulta.shell>
