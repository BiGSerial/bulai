@php use App\Models\PatientPrescription as P; @endphp
<x-consulta.shell title="Meus remédios" :back="route('consulta.inicio')">
    @if ($mensagem)<p role="status" class="rounded-xl bg-amber-50 px-4 py-3 font-medium text-amber-900">{{ $mensagem }}</p>@endif

    @unless ($consentido)
        <section class="grid gap-4 rounded-2xl border-2 border-amber-300 bg-amber-50 p-5 text-amber-900" aria-labelledby="consent-titulo">
            <h2 id="consent-titulo" class="text-2xl font-semibold leading-tight">Antes de começar: seus dados de saúde</h2>
            <p>Para avisar sobre alergias, ler suas receitas e considerar o que você já toma, o BULA-IA guarda suas <strong>alergias</strong>, <strong>receitas</strong> e <strong>remédios em uso</strong>.</p>
            <ul class="list-disc space-y-1 pl-6">
                <li>Só você vê esses dados.</li>
                <li>Você pode apagar tudo a qualquer momento, nesta página.</li>
                <li>É opcional: sem isso, as perguntas continuam funcionando.</li>
            </ul>
            <x-knowledge-admin.button wire:click="aceitarConsentimento" class="min-h-16 w-full text-xl" loadingTarget="aceitarConsentimento" loading="Salvando…">Concordo em guardar meus dados de saúde</x-knowledge-admin.button>
        </section>
    @else
        <div x-data="{ aba: @js($aba), buscar: {{ $buscou ? 'true' : 'false' }}, novaAlergia: false }" class="grid gap-4">
            <div role="tablist" aria-label="Seções" class="grid grid-cols-3 gap-1 rounded-2xl bg-slate-200/70 p-1">
                @foreach (['remedios' => ['Remédios', $emUso->count()], 'receitas' => ['Receitas', $receitas->count()], 'alergias' => ['Alergias', $alergias->count()]] as $chave => [$rotulo, $total])
                    <button type="button" role="tab" id="tab-{{ $chave }}" aria-controls="painel-{{ $chave }}" x-bind:aria-selected="aba === '{{ $chave }}'" x-bind:tabindex="aba === '{{ $chave }}' ? 0 : -1" x-on:click="aba = '{{ $chave }}'" x-bind:class="aba === '{{ $chave }}' ? 'bg-white text-brand-900 shadow-sm' : 'text-slate-700 hover:text-slate-900'" class="flex min-h-14 flex-col items-center justify-center rounded-xl px-1 text-lg font-semibold leading-tight focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">{{ $rotulo }}<span class="text-base font-medium">{{ $total }}</span></button>
                @endforeach
            </div>

            {{-- Remédios: lista grande + um botão "Adicionar remédio"; a busca só aparece após o toque. --}}
            <section id="painel-remedios" role="tabpanel" aria-labelledby="tab-remedios" x-show="aba === 'remedios'" x-cloak class="grid gap-3">
                <button type="button" class="inline-flex min-h-16 w-full items-center justify-center gap-2 rounded-xl bg-accent-600 px-4 text-xl font-semibold text-white shadow-sm hover:bg-accent-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" x-on:click="buscar = !buscar; $nextTick(() => buscar && $refs.buscaUso.focus())" x-bind:aria-expanded="buscar" aria-controls="busca-uso-bloco">+ Adicionar remédio</button>
                <div id="busca-uso-bloco" x-show="buscar" x-cloak class="grid gap-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <label for="busca-uso" class="text-lg font-semibold text-brand-900">Digite o nome do remédio</label>
                    <input id="busca-uso" x-ref="buscaUso" class="ui-control min-h-14 text-xl" type="search" inputmode="search" autocomplete="off" placeholder="Pelo menos 3 letras" wire:model.live.debounce.300ms="busca">
                    <div wire:loading wire:target="busca" class="text-base text-slate-600">Buscando…</div>
                    @if ($buscou)
                        @forelse ($resultados as $item)
                            <button type="button" wire:key="ru-{{ $item['id'] }}" wire:click="adicionarMedicamento({{ $item['id'] }})" wire:loading.attr="disabled" class="flex min-h-16 w-full items-center justify-between gap-3 rounded-xl border border-slate-300 px-4 py-2 text-left hover:border-brand-600 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                                <span class="min-w-0"><span class="block text-lg font-semibold text-slate-900">{{ $item['name'] }}</span><span class="block text-base text-slate-700">{{ \Illuminate\Support\Str::limit($item['ingredients'], 60) }}</span></span><span class="shrink-0 font-semibold text-brand-800">Adicionar</span>
                            </button>
                        @empty
                            <p class="text-slate-700">Não achamos “{{ $busca }}” no catálogo.</p>
                        @endforelse
                        <button type="button" class="min-h-14 w-full rounded-xl border-2 border-amber-400 bg-amber-50 px-4 text-lg font-semibold text-amber-900 hover:bg-amber-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="cadastrarPeloNome" wire:loading.attr="disabled">Não achei meu remédio: cadastrar assim mesmo</button>
                        <p class="text-base text-slate-700">Ele entra na sua lista com o nome que você digitou e nossa equipe confere depois.</p>
                    @endif
                </div>

                <ul class="grid gap-3" aria-label="Remédios que uso">
                    @forelse ($emUso as $item)
                        <li wire:key="us-{{ $item->id }}">
                            <article class="grid gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200" aria-labelledby="us-t-{{ $item->id }}" x-data="{ mais: false }">
                                <h3 id="us-t-{{ $item->id }}" class="text-xl font-semibold leading-snug text-brand-900">{{ $item->displayName() }}</h3>
                                @unless ($item->isMatched())<p class="rounded-xl bg-amber-50 px-3 py-2 text-base font-semibold text-amber-900">Não encontrado no catálogo: vamos conferir</p>@endunless
                                <p class="text-slate-800">
                                    @if ($item->dosage || $item->frequency){{ collect([$item->dosage, $item->frequency])->filter()->join(' · ') }}@else <span class="text-slate-600">Posologia não informada</span>@endif
                                    @if ($item->as_needed) · só quando precisar @elseif ($item->scheduledTimes() !== []) · às {{ implode(', ', $item->scheduledTimes()) }} @endif
                                    @if ($item->prescription) · receita de {{ $item->prescription->prescriber_name }}@endif
                                    @if ($item->ends_at) · até {{ $item->ends_at->format('d/m/Y') }}@endif
                                    @unless ($item->isActive()) · <span class="font-semibold text-slate-600">tratamento encerrado</span>@endunless
                                </p>
                                <button type="button" class="min-h-12 justify-self-start rounded-xl px-3 text-base font-semibold text-brand-800 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" x-on:click="mais = !mais" x-bind:aria-expanded="mais" x-text="mais ? 'Fechar opções' : 'Opções'">Opções</button>
                                <div x-show="mais" x-cloak class="grid gap-2">
                                    @if ($item->isMatched())<a class="inline-flex min-h-14 items-center justify-center rounded-xl border-2 border-brand-700 bg-white px-4 text-lg font-semibold text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('consulta.bula', $item->medicine_id) }}" wire:navigate aria-label="Bula humanizada de {{ $item->displayName() }}">Ver a bula simples</a>@endif
                                    <button type="button" class="inline-flex min-h-14 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-800 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="editarPosologia({{ $item->id }})" aria-label="Editar posologia de {{ $item->displayName() }}">Informar dose e horários</button>
                                    <button type="button" class="inline-flex min-h-14 items-center justify-center rounded-xl border border-rose-300 bg-white px-4 text-lg font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" wire:click="removerMedicamento({{ $item->id }})" wire:loading.attr="disabled" aria-label="Remover {{ $item->displayName() }}">Tirar da minha lista</button>
                                </div>
                                @if ($editandoId === $item->id)
                                    <form class="grid gap-3 rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200" wire:submit="salvarPosologia" aria-label="Posologia de {{ $item->displayName() }}">
                                        <div><label class="font-medium text-slate-800" for="dose-{{ $item->id }}">Dose</label><input id="dose-{{ $item->id }}" class="ui-control min-h-14 text-lg" wire:model="posologia.dosage" placeholder="Ex.: 1 comprimido de 500 mg" maxlength="120"></div>
                                        <div><label class="font-medium text-slate-800" for="freq-{{ $item->id }}">Quando tomar</label><input id="freq-{{ $item->id }}" class="ui-control min-h-14 text-lg" wire:model.blur="posologia.frequency" placeholder="Ex.: de 8 em 8 horas" maxlength="120"></div>
                                        <fieldset class="grid gap-2 rounded-xl bg-white p-3 ring-1 ring-slate-200">
                                            <legend class="px-1 font-medium text-slate-800">Horários do dia</legend>
                                            <label class="flex min-h-12 items-center gap-3 text-lg"><input type="checkbox" class="size-6 rounded border-slate-400 text-brand-700 focus:ring-brand-600" wire:model.live="posologia.as_needed"> Só quando precisar (sem horário fixo)</label>
                                            @unless ($posologia['as_needed'])
                                                @foreach ($posologia['times'] as $pos => $hora)
                                                    <div class="flex items-center gap-2" wire:key="ph-{{ $item->id }}-{{ $pos }}">
                                                        <label class="sr-only" for="hora-{{ $item->id }}-{{ $pos }}">Horário {{ $pos + 1 }}</label>
                                                        <input id="hora-{{ $item->id }}-{{ $pos }}" class="ui-control min-h-14 flex-1 text-xl" type="time" step="300" wire:model="posologia.times.{{ $pos }}">
                                                        <button type="button" class="min-h-14 shrink-0 rounded-xl px-4 text-lg font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" wire:click="tirarHorario({{ $pos }})" aria-label="Tirar o horário {{ $hora ?: $pos + 1 }}">Tirar</button>
                                                    </div>
                                                @endforeach
                                                @if (count($posologia['times']) < 12)<button type="button" class="min-h-14 rounded-xl border-2 border-dashed border-brand-600/50 bg-white px-4 text-lg font-semibold text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="adicionarHorario">+ Adicionar horário</button>@endif
                                                <label class="flex min-h-12 items-center gap-3 text-lg"><input type="checkbox" class="size-6 rounded border-slate-400 text-brand-700 focus:ring-brand-600" wire:model="posologia.reminders_enabled"> Lembrar nos horários</label>
                                            @endunless
                                        </fieldset>
                                        <div><label class="font-medium text-slate-800" for="ini-{{ $item->id }}">Começou em</label><input id="ini-{{ $item->id }}" class="ui-control min-h-14 text-lg" type="date" wire:model="posologia.started_at"></div>
                                        <div><label class="font-medium text-slate-800" for="fim-{{ $item->id }}">Termina em (vazio = uso contínuo)</label><input id="fim-{{ $item->id }}" class="ui-control min-h-14 text-lg" type="date" wire:model="posologia.ends_at"></div>
                                        <div><label class="font-medium text-slate-800" for="rec-{{ $item->id }}">Receita</label>
                                            <select id="rec-{{ $item->id }}" class="ui-control min-h-14 text-lg" wire:model="posologia.prescription_id">
                                                <option value="">Sem receita vinculada</option>
                                                @foreach ($receitas as $receita)<option value="{{ $receita->id }}">{{ $receita->prescriber_name }}{{ $receita->issued_at ? ' · '.$receita->issued_at->format('d/m/Y') : '' }}</option>@endforeach
                                            </select></div>
                                        <div><label class="font-medium text-slate-800" for="obs-{{ $item->id }}">Observações</label><input id="obs-{{ $item->id }}" class="ui-control min-h-14 text-lg" wire:model="posologia.notes" placeholder="Ex.: tomar após as refeições" maxlength="255"></div>
                                        <x-knowledge-admin.button type="submit" class="min-h-14 w-full text-lg" loadingTarget="salvarPosologia" loading="Salvando…">Salvar</x-knowledge-admin.button>
                                        <button type="button" class="min-h-14 rounded-xl border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-800" wire:click="cancelarPosologia">Cancelar</button>
                                    </form>
                                @endif
                            </article>
                        </li>
                    @empty
                        <li class="rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-slate-700">Nenhum remédio registrado. Toque em “Adicionar remédio” ou fotografe uma receita.</li>
                    @endforelse
                </ul>
                @if ($emUso->isNotEmpty())<x-knowledge-admin.button variant="secondary" :href="route('consulta.hoje')" class="min-h-14 w-full text-lg">Ver remédios de hoje</x-knowledge-admin.button>@endif
                <x-knowledge-admin.button variant="secondary" :href="route('consulta.receita.nova')" class="min-h-14 w-full text-lg">📷 Fotografar receita</x-knowledge-admin.button>
                @if ($emUso->count() >= 2)<x-knowledge-admin.button variant="secondary" :href="route('consulta.pesquisa', ['usar' => 1])" class="min-h-14 w-full text-lg">Verificar interações entre eles</x-knowledge-admin.button>@endif
            </section>

            {{-- Receitas --}}
            <section id="painel-receitas" role="tabpanel" aria-labelledby="tab-receitas" x-show="aba === 'receitas'" x-cloak class="grid gap-3">
                <x-knowledge-admin.button variant="accent" :href="route('consulta.receita.nova')" class="min-h-16 w-full text-xl">📷 Fotografar receita</x-knowledge-admin.button>
                <button type="button" class="inline-flex min-h-14 w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-800 shadow-sm hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="$toggle('mostrarFormularioReceita')" aria-expanded="{{ $mostrarFormularioReceita ? 'true' : 'false' }}" aria-controls="form-receita">{{ $mostrarFormularioReceita ? 'Fechar' : 'Cadastrar receita à mão' }}</button>
                @if ($mostrarFormularioReceita)
                    <form id="form-receita" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" wire:submit="adicionarReceita">
                        <div><label class="font-medium text-slate-800" for="prescritor">Médico(a) que receitou</label><input id="prescritor" class="ui-control min-h-14 text-lg" wire:model="prescritor" placeholder="Ex.: Dra. Ana Souza" maxlength="120" required></div>
                        <div><label class="font-medium text-slate-800" for="registro-prescritor">CRM (opcional)</label><input id="registro-prescritor" class="ui-control min-h-14 text-lg" wire:model="registroPrescritor" placeholder="Ex.: CRM-SP 123456" maxlength="40"></div>
                        <div><label class="font-medium text-slate-800" for="data-receita">Data da receita (opcional)</label><input id="data-receita" class="ui-control min-h-14 text-lg" type="date" wire:model="dataReceita" max="{{ now()->format('Y-m-d') }}"></div>
                        <div><label class="font-medium text-slate-800" for="obs-receita">Observações (opcional)</label><input id="obs-receita" class="ui-control min-h-14 text-lg" wire:model="observacoesReceita" placeholder="Ex.: uso contínuo" maxlength="500"></div>
                        <x-knowledge-admin.button type="submit" class="min-h-14 w-full text-lg" loadingTarget="adicionarReceita" loading="Salvando…">Adicionar receita</x-knowledge-admin.button>
                    </form>
                @endif
                <ul class="grid gap-3" aria-label="Receitas médicas">
                    @forelse ($receitas as $receita)
                        <li wire:key="rc-{{ $receita->id }}">
                            <article class="grid gap-2 rounded-2xl bg-white p-4 ring-1 ring-slate-200" aria-labelledby="rc-t-{{ $receita->id }}">
                                <h3 id="rc-t-{{ $receita->id }}" class="text-xl font-semibold leading-snug text-brand-900">{{ $receita->prescriber_name }}@if ($receita->prescriber_registration) <span class="text-base font-normal text-slate-600">· {{ $receita->prescriber_registration }}</span>@endif</h3>
                                <p class="text-slate-800">{{ $receita->issued_at?->format('d/m/Y') ?? 'sem data' }} · {{ $receita->source === P::SOURCE_PHOTO ? 'lida por foto' : 'cadastrada à mão' }}
                                    @if ($receita->status !== P::STATUS_CONFIRMED) · <span class="font-semibold {{ $receita->status === P::STATUS_FAILED ? 'text-rose-800' : 'text-amber-900' }}">{{ match ($receita->status) { P::STATUS_REVIEW => 'aguardando sua conferência', P::STATUS_FAILED => 'leitura falhou', default => 'em leitura' } }}</span>@endif
                                </p>
                                @if ($receita->medications->isNotEmpty())
                                    <ul class="grid gap-1 text-base text-slate-800" aria-label="Remédios desta receita">
                                        @foreach ($receita->medications as $med)<li>• {{ $med->displayName() }}@unless ($med->isMatched()) <span class="text-amber-900">(a conferir)</span> @endunless @if ($med->dosage || $med->frequency) · {{ collect([$med->dosage, $med->frequency])->filter()->join(', ') }}@endif</li>@endforeach
                                    </ul>
                                @endif
                                @if ($receita->notes)<p class="text-base text-slate-700">{{ $receita->notes }}</p>@endif
                                @if ($receita->source === P::SOURCE_PHOTO && $receita->status !== P::STATUS_CONFIRMED)
                                    <a class="inline-flex min-h-14 items-center justify-center rounded-xl bg-brand-700 px-4 text-lg font-semibold text-white hover:bg-brand-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" href="{{ route('consulta.receita.revisar', $receita) }}" wire:navigate>{{ $receita->status === P::STATUS_REVIEW ? 'Conferir a receita' : 'Abrir' }}</a>
                                @endif
                                <button type="button" class="inline-flex min-h-12 items-center justify-center rounded-xl px-3 text-base font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" wire:click="removerReceita({{ $receita->id }})" wire:loading.attr="disabled" aria-label="Remover receita de {{ $receita->prescriber_name }}">Apagar esta receita</button>
                            </article>
                        </li>
                    @empty
                        <li class="rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-slate-700">Nenhuma receita registrada. Fotografe a sua ou cadastre à mão.</li>
                    @endforelse
                </ul>
            </section>

            {{-- Alergias --}}
            <section id="painel-alergias" role="tabpanel" aria-labelledby="tab-alergias" x-show="aba === 'alergias'" x-cloak class="grid gap-3">
                <button type="button" class="inline-flex min-h-16 w-full items-center justify-center rounded-xl bg-accent-600 px-4 text-xl font-semibold text-white shadow-sm hover:bg-accent-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" x-on:click="novaAlergia = !novaAlergia; $nextTick(() => novaAlergia && $refs.substancia.focus())" x-bind:aria-expanded="novaAlergia" aria-controls="form-alergia">+ Adicionar alergia</button>
                <form id="form-alergia" x-show="novaAlergia" x-cloak class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" wire:submit="adicionarAlergia">
                    <div><label class="font-medium text-slate-800" for="substancia">A que você é alérgico?</label><input id="substancia" x-ref="substancia" class="ui-control min-h-14 text-lg" wire:model="substancia" placeholder="Ex.: dipirona, penicilina" maxlength="120" required></div>
                    <div><label class="font-medium text-slate-800" for="reacao">O que acontece? (opcional)</label><input id="reacao" class="ui-control min-h-14 text-lg" wire:model="reacao" placeholder="Ex.: coceira, inchaço" maxlength="255"></div>
                    <x-knowledge-admin.button type="submit" class="min-h-14 w-full text-lg" loadingTarget="adicionarAlergia" loading="Salvando…">Adicionar</x-knowledge-admin.button>
                </form>
                <ul class="grid gap-3" aria-label="Alergias">
                    @forelse ($alergias as $alergia)
                        <li wire:key="al-{{ $alergia->id }}" class="grid gap-2 rounded-2xl bg-rose-50 p-4 ring-1 ring-rose-200">
                            <p class="text-xl font-semibold leading-snug text-rose-900">{{ $alergia->substance }}</p>
                            @if ($alergia->reaction)<p class="text-slate-800">{{ $alergia->reaction }}</p>@endif
                            <button type="button" class="inline-flex min-h-12 items-center justify-center justify-self-start rounded-xl px-3 text-base font-semibold text-rose-800 hover:bg-rose-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" wire:click="removerAlergia({{ $alergia->id }})" wire:loading.attr="disabled" aria-label="Remover alergia a {{ $alergia->substance }}">Tirar da lista</button>
                        </li>
                    @empty
                        <li class="rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-slate-700">Nenhuma alergia registrada.</li>
                    @endforelse
                </ul>
            </section>
        </div>

        <div x-data="{ confirmar: false }" class="grid gap-2">
            <button type="button" x-show="!confirmar" class="min-h-12 text-base font-semibold text-rose-800 underline" x-on:click="confirmar = true">Apagar todos os meus dados de saúde</button>
            <div x-cloak x-show="confirmar" class="grid gap-2 rounded-2xl border border-rose-300 bg-rose-50 p-4">
                <p class="font-semibold text-rose-900">Apagar tudo? Remove alergias, receitas, remédios, perguntas e o consentimento.</p>
                <button type="button" class="min-h-14 rounded-xl bg-rose-700 px-4 text-lg font-semibold text-white" wire:click="apagarDadosDeSaude" wire:loading.attr="disabled">Sim, apagar tudo</button>
                <button type="button" class="min-h-14 rounded-xl border border-slate-300 bg-white px-4 text-lg font-semibold text-slate-800" x-on:click="confirmar = false">Não</button>
            </div>
        </div>
    @endunless
</x-consulta.shell>
