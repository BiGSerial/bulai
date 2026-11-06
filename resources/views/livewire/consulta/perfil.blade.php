@php use App\Models\PatientPrescription as P; @endphp
<x-consulta.shell title="Meus remédios" :back="route('consulta.inicio')">
    @if ($mensagem)<p role="status" class="rounded-2xl bg-amber-50 px-4 py-3 font-medium text-amber-900 ring-1 ring-amber-300">{{ $mensagem }}</p>@endif

    @unless ($consentido)
        <x-consulta.cartao tone="warning" aria-labelledby="consent-titulo">
            <div class="flex items-start gap-3">
                <x-consulta.icone name="escudo" tone="warning" size="lg" />
                <div class="min-w-0">
                    <h2 id="consent-titulo" class="text-2xl font-semibold leading-tight">Antes de começar: seus dados de saúde</h2>
                    <p class="mt-1">Para avisar sobre alergias, ler suas receitas e considerar o que você já toma, o BULA-IA guarda suas <strong>alergias</strong>, <strong>receitas</strong> e <strong>remédios em uso</strong>.</p>
                </div>
            </div>
            <ul class="grid gap-2">
                <li class="flex items-center gap-2"><x-consulta.icone name="check" plain class="size-6 shrink-0 text-emerald-700" />Só você vê esses dados.</li>
                <li class="flex items-center gap-2"><x-consulta.icone name="check" plain class="size-6 shrink-0 text-emerald-700" />Você pode apagar tudo a qualquer momento, nesta página.</li>
                <li class="flex items-center gap-2"><x-consulta.icone name="check" plain class="size-6 shrink-0 text-emerald-700" />É opcional: sem isso, as perguntas continuam funcionando.</li>
            </ul>
            <x-consulta.botao wire:click="aceitarConsentimento" loadingTarget="aceitarConsentimento" loading="Salvando…">Concordo em guardar meus dados de saúde</x-consulta.botao>
        </x-consulta.cartao>
    @else
        <div x-data="{ aba: @js($aba), buscar: {{ $buscou ? 'true' : 'false' }}, novaAlergia: false }" class="grid gap-4">
            <x-consulta.segmentos modelo="aba" :itens="['remedios' => ['Em uso', $emUso->count()], 'receitas' => ['Receitas', $receitas->count()], 'alergias' => ['Alergias', $alergias->count()]]" prefixo="tab" />

            {{-- Em uso: um botão "Adicionar remédio"; a busca só aparece após o toque. --}}
            <section id="painel-remedios" role="tabpanel" aria-labelledby="tab-remedios" x-show="aba === 'remedios'" x-cloak class="grid gap-3">
                <x-consulta.botao variant="accent" x-on:click="buscar = !buscar; $nextTick(() => buscar && $refs.buscaUso.focus())" x-bind:aria-expanded="buscar" aria-controls="busca-uso-bloco"><x-consulta.icone name="mais" plain class="size-7" />Adicionar remédio</x-consulta.botao>
                <x-consulta.cartao id="busca-uso-bloco" x-show="buscar" x-cloak>
                    <label for="busca-uso" class="text-lg font-semibold text-brand-900">Digite o nome do remédio</label>
                    <input id="busca-uso" x-ref="buscaUso" class="ui-control min-h-14 text-xl" type="search" inputmode="search" autocomplete="off" placeholder="Pelo menos 3 letras" wire:model.live.debounce.300ms="busca">
                    <div wire:loading wire:target="busca" class="text-base text-slate-600">Buscando…</div>
                    @if ($buscou)
                        @if ($resultados !== [] && ($resultados[0]['fuzzy'] ?? false))<p class="rounded-xl bg-brand-50 px-3 py-2 text-base font-semibold text-brand-900">Você quis dizer…?</p>@endif
                        @forelse ($resultados as $item)
                            <button type="button" wire:key="ru-{{ $item['id'] }}" wire:click="adicionarMedicamento({{ $item['id'] }})" wire:loading.attr="disabled" class="flex min-h-16 w-full min-w-0 items-center justify-between gap-3 rounded-2xl border border-slate-300 px-4 py-2 text-left transition hover:border-brand-600 hover:bg-brand-50 active:scale-[0.99] focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                                <span class="min-w-0"><span class="block break-words text-lg font-semibold text-slate-900">{{ $item['name'] }}</span><span class="block break-words text-base text-slate-700">{{ \Illuminate\Support\Str::limit($item['ingredients'], 60) }}</span></span><span class="shrink-0 font-semibold text-brand-800">Adicionar</span>
                            </button>
                        @empty
                            <p class="text-slate-700">Não achamos “{{ $busca }}” no catálogo.</p>
                        @endforelse
                        <button type="button" class="min-h-14 w-full rounded-2xl border-2 border-amber-400 bg-amber-50 px-4 text-lg font-semibold leading-snug text-amber-900 hover:bg-amber-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="cadastrarPeloNome" wire:loading.attr="disabled">Não achei meu remédio: cadastrar assim mesmo</button>
                        <p class="text-base text-slate-700">Ele entra na sua lista com o nome que você digitou e nossa equipe confere depois.</p>
                    @endif
                </x-consulta.cartao>

                <ul class="grid gap-3" aria-label="Remédios que uso">
                    @forelse ($emUso as $item)
                        <li wire:key="us-{{ $item->id }}">
                            <article class="grid min-w-0 gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200 {{ $item->isActive() ? '' : 'opacity-75' }}" aria-labelledby="us-t-{{ $item->id }}" x-data="{ folha: false, confirmarRemover: false }">
                                <div class="flex min-w-0 items-start gap-3">
                                    <x-consulta.icone name="pilula" :tone="$item->isMatched() ? 'brand' : 'warning'" />
                                    <div class="min-w-0 flex-1">
                                        <h3 id="us-t-{{ $item->id }}" class="break-words text-xl font-semibold leading-snug text-brand-900">{{ $item->displayName() }}</h3>
                                        <p class="break-words text-slate-800">
                                            @if ($item->dosage || $item->frequency){{ collect([$item->dosage, $item->frequency])->filter()->join(' · ') }}@else <span class="text-slate-600">Posologia não informada</span>@endif
                                            @if ($item->as_needed) · só quando precisar @endif
                                            @if ($item->prescription) · receita de {{ $item->prescription->prescriber_name }}@endif
                                            @if ($item->ends_at) · até {{ $item->ends_at->format('d/m/Y') }}@endif
                                        </p>
                                    </div>
                                    <button type="button" class="inline-flex size-12 shrink-0 items-center justify-center rounded-full text-brand-800 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" x-on:click="folha = true; confirmarRemover = false" aria-label="Opções de {{ $item->displayName() }}" aria-haspopup="dialog"><svg class="size-7" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm6 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm6 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" /></svg></button>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    @if ($item->as_needed)
                                        <x-consulta.selo tone="neutral">Sem horário fixo</x-consulta.selo>
                                    @elseif ($item->isDynamic())
                                        <x-consulta.selo tone="brand">{{ $item->intervalLabel() }} · ajusta pelo horário em que você toma</x-consulta.selo>
                                    @else
                                        @foreach ($item->scheduledTimes() as $hora)<x-consulta.selo tone="brand" class="tabular-nums">{{ $hora }}</x-consulta.selo>@endforeach
                                        @if ($item->scheduledTimes() === [])<x-consulta.selo tone="neutral">Sem horários</x-consulta.selo>@endif
                                    @endif
                                    @unless ($item->isMatched())<x-consulta.selo tone="warning">Sem bula no catálogo</x-consulta.selo>@endunless
                                    @unless ($item->isActive())<x-consulta.selo tone="neutral">Tratamento encerrado</x-consulta.selo>@endunless
                                </div>
                                @unless ($item->isMatched())<p class="sr-only">Não encontrado no catálogo: vamos conferir</p>@endunless
                                @if ($item->scheduledTimes() !== [] && ! $item->as_needed && ! $item->isDynamic())<p class="sr-only">às {{ implode(', ', $item->scheduledTimes()) }}</p>@endif

                                <x-consulta.folha-acoes modelo="folha" :titulo="$item->displayName()">
                                    <div class="grid gap-2" x-show="!confirmarRemover">
                                        @if ($item->isMatched())<x-consulta.botao variant="soft" size="md" :href="route('consulta.bula', $item->medicine_id)" aria-label="Bula humanizada de {{ $item->displayName() }}"><x-consulta.icone name="bula" plain class="size-6" />Ver a bula simples</x-consulta.botao>@endif
                                        <x-consulta.botao variant="soft" size="md" wire:click="editarPosologia({{ $item->id }})" x-on:click="folha = false" aria-label="Editar posologia de {{ $item->displayName() }}"><x-consulta.icone name="editar" plain class="size-6" />Informar dose e horários</x-consulta.botao>
                                        <button type="button" class="inline-flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl border border-rose-300 bg-white px-4 text-lg font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-rose-600/30" x-on:click="confirmarRemover = true"><x-consulta.icone name="apagar" plain class="size-6" />Tirar da minha lista</button>
                                    </div>
                                    <div class="grid gap-2" x-show="confirmarRemover" x-cloak>
                                        <p class="font-semibold text-rose-900">Tirar {{ $item->displayName() }} da sua lista? As marcações deste remédio também somem.</p>
                                        <x-consulta.botao variant="danger" size="md" wire:click="removerMedicamento({{ $item->id }})" wire:loading.attr="disabled" aria-label="Remover {{ $item->displayName() }}">Sim, tirar</x-consulta.botao>
                                        <x-consulta.botao variant="quiet" size="sm" x-on:click="confirmarRemover = false">Não</x-consulta.botao>
                                    </div>
                                </x-consulta.folha-acoes>

                                @if ($editandoId === $item->id)
                                    <form class="grid gap-3 rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200" wire:submit="salvarPosologia" aria-label="Posologia de {{ $item->displayName() }}">
                                        <p class="text-lg font-semibold text-brand-900">Dose e horários</p>
                                        <div><label class="font-medium text-slate-800" for="dose-{{ $item->id }}">Dose</label><input id="dose-{{ $item->id }}" class="ui-control min-h-14 text-lg" wire:model="posologia.dosage" placeholder="Ex.: 1 comprimido de 500 mg" maxlength="120"></div>
                                        <div><label class="font-medium text-slate-800" for="freq-{{ $item->id }}">Quando tomar</label><input id="freq-{{ $item->id }}" class="ui-control min-h-14 text-lg" wire:model.blur="posologia.frequency" placeholder="Ex.: de 8 em 8 horas" maxlength="120"><p class="mt-1 text-base text-slate-600">Os horários abaixo são sugeridos a partir disto.</p></div>
                                        <fieldset class="grid gap-2 rounded-2xl bg-white p-3 ring-1 ring-slate-200">
                                            <legend class="px-1 font-medium text-slate-800">Horários do dia</legend>
                                            <label class="flex min-h-12 items-center gap-3 text-lg"><input type="checkbox" class="size-6 rounded border-slate-400 text-brand-700 focus:ring-brand-600" wire:model.live="posologia.as_needed"> Só quando precisar (sem horário fixo)</label>
                                            @unless ($posologia['as_needed'])
                                                @php($dinamico = $posologia['interval_hours'] !== null && $posologia['schedule_mode'] === \App\Models\PatientMedication::MODE_INTERVAL)
                                                @if ($posologia['interval_hours'] !== null)
                                                    {{-- Frequência por intervalo: contar a partir de quando toma (recomendado) ou horários fixos. --}}
                                                    <div class="grid gap-2" role="radiogroup" aria-label="Como contar os horários">
                                                        <label class="flex min-h-16 cursor-pointer items-start gap-3 rounded-2xl border-2 p-3 {{ $dinamico ? 'border-brand-700 bg-brand-50' : 'border-slate-300 bg-white' }}">
                                                            <input type="radio" class="mt-1 size-6 shrink-0 border-slate-400 text-brand-700 focus:ring-brand-600" name="modo-{{ $item->id }}" value="{{ \App\Models\PatientMedication::MODE_INTERVAL }}" wire:model.live="posologia.schedule_mode">
                                                            <span class="min-w-0"><span class="block break-words text-lg font-semibold text-slate-900">Contar a partir de quando eu tomar (recomendado)</span><span class="block break-words text-base text-slate-600">A cada {{ $posologia['interval_hours'] }} h depois do horário em que você marcar “Tomei”.</span></span>
                                                        </label>
                                                        <label class="flex min-h-16 cursor-pointer items-start gap-3 rounded-2xl border-2 p-3 {{ ! $dinamico ? 'border-brand-700 bg-brand-50' : 'border-slate-300 bg-white' }}">
                                                            <input type="radio" class="mt-1 size-6 shrink-0 border-slate-400 text-brand-700 focus:ring-brand-600" name="modo-{{ $item->id }}" value="{{ \App\Models\PatientMedication::MODE_FIXED }}" wire:model.live="posologia.schedule_mode">
                                                            <span class="min-w-0"><span class="block break-words text-lg font-semibold text-slate-900">Horários fixos todos os dias</span><span class="block break-words text-base text-slate-600">Sempre nos mesmos horários, mesmo se atrasar uma dose.</span></span>
                                                        </label>
                                                    </div>
                                                @endif
                                                @if ($dinamico)
                                                    <div class="flex min-w-0 items-center gap-2">
                                                        <label class="min-w-0 flex-1 font-medium text-slate-800" for="hora-{{ $item->id }}-0">Primeiro horário do dia</label>
                                                        <input id="hora-{{ $item->id }}-0" class="ui-control min-h-14 w-32 max-w-full text-xl" type="time" step="300" wire:model="posologia.times.0">
                                                    </div>
                                                    <p class="text-base text-slate-600">Os outros horários são calculados a cada {{ $posologia['interval_hours'] }} h a partir da última dose que você marcar.</p>
                                                @else
                                                    @foreach ($posologia['times'] as $pos => $hora)
                                                        <div class="flex min-w-0 items-center gap-2" wire:key="ph-{{ $item->id }}-{{ $pos }}">
                                                            <label class="sr-only" for="hora-{{ $item->id }}-{{ $pos }}">Horário {{ $pos + 1 }}</label>
                                                            <input id="hora-{{ $item->id }}-{{ $pos }}" class="ui-control min-h-14 min-w-0 flex-1 text-xl" type="time" step="300" wire:model="posologia.times.{{ $pos }}">
                                                            <button type="button" class="min-h-14 shrink-0 rounded-2xl px-4 text-lg font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" wire:click="tirarHorario({{ $pos }})" aria-label="Tirar o horário {{ $hora ?: $pos + 1 }}">Tirar</button>
                                                        </div>
                                                    @endforeach
                                                    @if (count($posologia['times']) < 12)<button type="button" class="min-h-14 rounded-2xl border-2 border-dashed border-brand-600/50 bg-white px-4 text-lg font-semibold text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="adicionarHorario">+ Adicionar horário</button>@endif
                                                @endif
                                                <label class="flex min-h-12 items-center gap-3 text-lg"><input type="checkbox" class="size-6 rounded border-slate-400 text-brand-700 focus:ring-brand-600" wire:model="posologia.reminders_enabled"> Lembrar nos horários</label>
                                            @endunless
                                        </fieldset>
                                        <x-consulta.secao titulo="Mais detalhes" resumo="Datas, receita e observações" icon="calendario" tone="neutral">
                                            <div><label class="font-medium text-slate-800" for="ini-{{ $item->id }}">Começou em</label><input id="ini-{{ $item->id }}" class="ui-control min-h-14 text-lg" type="date" wire:model="posologia.started_at"></div>
                                            <div><label class="font-medium text-slate-800" for="fim-{{ $item->id }}">Termina em (vazio = uso contínuo)</label><input id="fim-{{ $item->id }}" class="ui-control min-h-14 text-lg" type="date" wire:model="posologia.ends_at"></div>
                                            <div><label class="font-medium text-slate-800" for="rec-{{ $item->id }}">Receita</label>
                                                <select id="rec-{{ $item->id }}" class="ui-control min-h-14 text-lg" wire:model="posologia.prescription_id">
                                                    <option value="">Sem receita vinculada</option>
                                                    @foreach ($receitas as $receita)<option value="{{ $receita->id }}">{{ $receita->prescriber_name }}{{ $receita->issued_at ? ' · '.$receita->issued_at->format('d/m/Y') : '' }}</option>@endforeach
                                                </select></div>
                                            <div><label class="font-medium text-slate-800" for="obs-{{ $item->id }}">Observações</label><input id="obs-{{ $item->id }}" class="ui-control min-h-14 text-lg" wire:model="posologia.notes" placeholder="Ex.: tomar após as refeições" maxlength="255"></div>
                                        </x-consulta.secao>
                                        <x-consulta.botao type="submit" size="md" loadingTarget="salvarPosologia" loading="Salvando…">Salvar</x-consulta.botao>
                                        <x-consulta.botao variant="quiet" size="sm" wire:click="cancelarPosologia">Cancelar</x-consulta.botao>
                                    </form>
                                @endif
                            </article>
                        </li>
                    @empty
                        <li><x-consulta.vazio icon="pilula" titulo="Nenhum remédio registrado." texto="Toque em “Adicionar remédio” ou fotografe uma receita." /></li>
                    @endforelse
                </ul>
                <div class="grid gap-2">
                    @if ($emUso->isNotEmpty())<x-consulta.botao variant="soft" size="md" :href="route('consulta.hoje')"><x-consulta.icone name="relogio" plain class="size-6" />Ver remédios de hoje</x-consulta.botao>@endif
                    <x-consulta.botao variant="secondary" size="md" :href="route('consulta.receita.nova')"><x-consulta.icone name="camera" plain class="size-6" />Fotografar receita</x-consulta.botao>
                    @if ($emUso->count() >= 2)<x-consulta.botao variant="secondary" size="md" :href="route('consulta.pesquisa', ['usar' => 1])"><x-consulta.icone name="lupa" plain class="size-6" />Verificar interações entre eles</x-consulta.botao>@endif
                </div>
            </section>

            {{-- Receitas --}}
            <section id="painel-receitas" role="tabpanel" aria-labelledby="tab-receitas" x-show="aba === 'receitas'" x-cloak class="grid gap-3">
                <x-consulta.botao variant="accent" :href="route('consulta.receita.nova')"><x-consulta.icone name="camera" plain class="size-7" />Fotografar receita</x-consulta.botao>
                <x-consulta.botao variant="secondary" size="md" wire:click="$toggle('mostrarFormularioReceita')" aria-expanded="{{ $mostrarFormularioReceita ? 'true' : 'false' }}" aria-controls="form-receita">{{ $mostrarFormularioReceita ? 'Fechar' : 'Cadastrar receita à mão' }}</x-consulta.botao>
                @if ($mostrarFormularioReceita)
                    <form id="form-receita" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" wire:submit="adicionarReceita">
                        <div><label class="font-medium text-slate-800" for="prescritor">Médico(a) que receitou</label><input id="prescritor" class="ui-control min-h-14 text-lg" wire:model="prescritor" placeholder="Ex.: Dra. Ana Souza" maxlength="120" required></div>
                        <div><label class="font-medium text-slate-800" for="registro-prescritor">CRM (opcional)</label><input id="registro-prescritor" class="ui-control min-h-14 text-lg" wire:model="registroPrescritor" placeholder="Ex.: CRM-SP 123456" maxlength="40"></div>
                        <div><label class="font-medium text-slate-800" for="data-receita">Data da receita (opcional)</label><input id="data-receita" class="ui-control min-h-14 text-lg" type="date" wire:model="dataReceita" max="{{ now()->format('Y-m-d') }}"></div>
                        <div><label class="font-medium text-slate-800" for="obs-receita">Observações (opcional)</label><input id="obs-receita" class="ui-control min-h-14 text-lg" wire:model="observacoesReceita" placeholder="Ex.: uso contínuo" maxlength="500"></div>
                        <x-consulta.botao type="submit" size="md" loadingTarget="adicionarReceita" loading="Salvando…">Adicionar receita</x-consulta.botao>
                    </form>
                @endif
                <ul class="grid gap-3" aria-label="Receitas médicas">
                    @forelse ($receitas as $receita)
                        <li wire:key="rc-{{ $receita->id }}">
                            <article class="grid min-w-0 gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200" aria-labelledby="rc-t-{{ $receita->id }}" x-data="{ confirmar: false }">
                                <div class="flex min-w-0 items-start gap-3">
                                    <x-consulta.icone name="receita" :tone="$receita->status === P::STATUS_CONFIRMED ? 'brand' : ($receita->status === P::STATUS_FAILED ? 'danger' : 'warning')" />
                                    <div class="min-w-0 flex-1">
                                        <h3 id="rc-t-{{ $receita->id }}" class="break-words text-xl font-semibold leading-snug text-brand-900">{{ $receita->prescriber_name }}@if ($receita->prescriber_registration) <span class="text-base font-normal text-slate-600">· {{ $receita->prescriber_registration }}</span>@endif</h3>
                                        <p class="break-words text-slate-800">{{ $receita->issued_at?->format('d/m/Y') ?? 'sem data' }} · {{ $receita->source === P::SOURCE_PHOTO ? 'lida por foto' : 'cadastrada à mão' }}</p>
                                    </div>
                                </div>
                                @if ($receita->status !== P::STATUS_CONFIRMED)
                                    <x-consulta.selo :tone="$receita->status === P::STATUS_FAILED ? 'danger' : 'warning'" class="justify-self-start">{{ match ($receita->status) { P::STATUS_REVIEW => 'aguardando sua conferência', P::STATUS_FAILED => 'leitura falhou', default => 'em leitura' } }}</x-consulta.selo>
                                @endif
                                @if ($receita->medications->isNotEmpty())
                                    <ul class="grid gap-1 text-base text-slate-800" aria-label="Remédios desta receita">
                                        @foreach ($receita->medications as $med)<li class="flex gap-2"><span aria-hidden="true">•</span><span class="min-w-0 break-words">{{ $med->displayName() }}@unless ($med->isMatched()) <span class="text-amber-900">(a conferir)</span> @endunless @if ($med->dosage || $med->frequency) · {{ collect([$med->dosage, $med->frequency])->filter()->join(', ') }}@endif</span></li>@endforeach
                                    </ul>
                                @endif
                                @if ($receita->notes)<p class="break-words text-base text-slate-700">{{ $receita->notes }}</p>@endif
                                @if ($receita->source === P::SOURCE_PHOTO && $receita->status !== P::STATUS_CONFIRMED)
                                    <x-consulta.botao size="md" :href="route('consulta.receita.revisar', $receita)">{{ $receita->status === P::STATUS_REVIEW ? 'Conferir a receita' : 'Abrir' }}</x-consulta.botao>
                                @endif
                                <button type="button" x-show="!confirmar" class="inline-flex min-h-12 items-center justify-center justify-self-start rounded-xl px-3 text-base font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" x-on:click="confirmar = true" aria-label="Remover receita de {{ $receita->prescriber_name }}">Apagar esta receita</button>
                                <div x-cloak x-show="confirmar" class="grid gap-2 rounded-2xl bg-rose-50 p-3 ring-1 ring-rose-200">
                                    <p class="font-semibold text-rose-900">Apagar esta receita?</p>
                                    <x-consulta.botao variant="danger" size="md" wire:click="removerReceita({{ $receita->id }})" wire:loading.attr="disabled">Sim, apagar</x-consulta.botao>
                                    <x-consulta.botao variant="quiet" size="sm" x-on:click="confirmar = false">Não</x-consulta.botao>
                                </div>
                            </article>
                        </li>
                    @empty
                        <li><x-consulta.vazio icon="receita" titulo="Nenhuma receita registrada." texto="Fotografe a sua ou cadastre à mão." /></li>
                    @endforelse
                </ul>
            </section>

            {{-- Alergias --}}
            <section id="painel-alergias" role="tabpanel" aria-labelledby="tab-alergias" x-show="aba === 'alergias'" x-cloak class="grid gap-3">
                <x-consulta.botao variant="accent" x-on:click="novaAlergia = !novaAlergia; $nextTick(() => novaAlergia && $refs.substancia.focus())" x-bind:aria-expanded="novaAlergia" aria-controls="form-alergia"><x-consulta.icone name="mais" plain class="size-7" />Adicionar alergia</x-consulta.botao>
                <form id="form-alergia" x-show="novaAlergia" x-cloak class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" wire:submit="adicionarAlergia">
                    <div><label class="font-medium text-slate-800" for="substancia">A que você é alérgico?</label><input id="substancia" x-ref="substancia" class="ui-control min-h-14 text-lg" wire:model="substancia" placeholder="Ex.: dipirona, penicilina" maxlength="120" required></div>
                    <div><label class="font-medium text-slate-800" for="reacao">O que acontece? (opcional)</label><input id="reacao" class="ui-control min-h-14 text-lg" wire:model="reacao" placeholder="Ex.: coceira, inchaço" maxlength="255"></div>
                    <x-consulta.botao type="submit" size="md" loadingTarget="adicionarAlergia" loading="Salvando…">Adicionar</x-consulta.botao>
                </form>
                <ul class="grid gap-3" aria-label="Alergias">
                    @forelse ($alergias as $alergia)
                        <li wire:key="al-{{ $alergia->id }}" class="flex min-w-0 items-start gap-3 rounded-2xl bg-rose-50 p-4 ring-1 ring-rose-200">
                            <x-consulta.icone name="alerta" tone="danger" />
                            <div class="min-w-0 flex-1">
                                <p class="break-words text-xl font-semibold leading-snug text-rose-900">{{ $alergia->substance }}</p>
                                @if ($alergia->reaction)<p class="break-words text-slate-800">{{ $alergia->reaction }}</p>@endif
                            </div>
                            <button type="button" class="inline-flex min-h-12 shrink-0 items-center justify-center rounded-xl px-3 text-base font-semibold text-rose-800 hover:bg-rose-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" wire:click="removerAlergia({{ $alergia->id }})" wire:loading.attr="disabled" aria-label="Remover alergia a {{ $alergia->substance }}">Tirar</button>
                        </li>
                    @empty
                        <li><x-consulta.vazio icon="coracao" tone="danger" titulo="Nenhuma alergia registrada." texto="Se você tem alguma, registre: o app avisa quando um remédio tem relação com ela." /></li>
                    @endforelse
                </ul>
            </section>
        </div>

        <div x-data="{ confirmar: false }" class="grid gap-2">
            <button type="button" x-show="!confirmar" class="min-h-12 text-base font-semibold text-rose-800 underline" x-on:click="confirmar = true">Apagar todos os meus dados de saúde</button>
            <div x-cloak x-show="confirmar" class="grid gap-2 rounded-2xl border border-rose-300 bg-rose-50 p-4">
                <p class="font-semibold text-rose-900">Apagar tudo? Remove alergias, receitas, remédios, perguntas e o consentimento.</p>
                <x-consulta.botao variant="danger" size="md" wire:click="apagarDadosDeSaude" wire:loading.attr="disabled">Sim, apagar tudo</x-consulta.botao>
                <x-consulta.botao variant="quiet" size="sm" x-on:click="confirmar = false">Não</x-consulta.botao>
            </div>
        </div>
    @endunless
</x-consulta.shell>
