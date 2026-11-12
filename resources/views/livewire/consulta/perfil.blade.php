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
        {{-- Resumo em PDF para levar à consulta: remédios, alergias, alertas e adesão (MedicalSummaryService). --}}
        <a href="{{ route('consulta.resumo') }}" class="flex min-h-16 min-w-0 items-center gap-3 rounded-2xl bg-brand-700 px-4 py-3 text-white shadow-sm hover:bg-brand-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" download>
            <x-consulta.icone name="baixar" tone="white" size="sm" />
            <span class="min-w-0 flex-1"><span class="block break-words text-lg font-semibold leading-snug">Resumo para o médico (PDF)</span><span class="block break-words text-sm text-white/90">Remédios, alergias, alertas e como você tem tomado</span></span>
            <span class="shrink-0 text-2xl" aria-hidden="true">›</span>
        </a>

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

                <ul class="grid gap-2" aria-label="Remédios que uso">
                    @forelse ($emUso as $item)
                        <li wire:key="us-{{ $item->id }}">
                            <article class="grid min-w-0 gap-2.5 rounded-2xl bg-white p-3 ring-1 ring-slate-200 sm:p-4" aria-labelledby="us-t-{{ $item->id }}" x-data="{ folha: false, confirmarRemover: false }">
                                <div class="flex min-w-0 items-start gap-3">
                                    <x-consulta.icone name="pilula" size="sm" :tone="$item->isMatched() ? 'brand' : 'warning'" class="mt-0.5" />
                                    <div class="min-w-0 flex-1">
                                        <h3 id="us-t-{{ $item->id }}" class="break-words text-lg font-semibold leading-snug text-brand-900">{{ $item->displayName() }}</h3>
                                        <p class="break-words text-base leading-snug text-slate-800">
                                            @if ($item->dosage || $item->frequency){{ collect([$item->dosage, $item->frequency])->filter()->join(' · ') }}@else<span class="text-slate-600">Dose não informada</span>@endif
                                        </p>
                                    </div>
                                    <button type="button" class="-mr-1 -mt-1 inline-flex size-11 shrink-0 items-center justify-center rounded-full text-brand-800 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" x-on:click="folha = true; confirmarRemover = false" aria-label="Opções de {{ $item->displayName() }}" aria-haspopup="dialog"><svg class="size-7" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm6 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm6 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" /></svg></button>
                                </div>

                                {{-- Quando tomar (horários ou intervalo) e até quando, numa linha; a receita fica em letra menor embaixo. --}}
                                <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                                    <x-consulta.icone name="relogio" plain class="size-5 shrink-0 text-slate-500" />
                                    @if ($item->as_needed)
                                        <x-consulta.selo tone="neutral" class="!px-2.5 !py-0.5 !text-sm">Só quando precisar</x-consulta.selo>
                                    @elseif ($item->isDynamic())
                                        <x-consulta.selo tone="brand" class="!px-2.5 !py-0.5 !text-sm">{{ $item->intervalLabel() }}, a partir da última dose</x-consulta.selo>
                                    @else
                                        @foreach ($item->scheduledTimes() as $hora)<x-consulta.selo tone="brand" class="!px-2.5 !py-0.5 !text-sm tabular-nums">{{ $hora }}</x-consulta.selo>@endforeach
                                        @if ($item->scheduledTimes() === [])<x-consulta.selo tone="neutral" class="!px-2.5 !py-0.5 !text-sm">Sem horários</x-consulta.selo>@endif
                                    @endif
                                    @if ($item->ends_at)<span class="ml-auto shrink-0 text-sm text-slate-600">até {{ $item->ends_at->format('d/m/Y') }}</span>@endif
                                </div>
                                @if ($item->prescription || ! $item->isMatched())
                                    <p class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 border-t border-slate-100 pt-2 text-sm text-slate-600">
                                        @if ($item->prescription)<span class="min-w-0 truncate">Receita de {{ $item->prescription->prescriberDisplayName() }}</span>@endif
                                        @unless ($item->isMatched())<x-consulta.selo tone="warning" class="!px-2 !py-0.5 !text-sm">Sem bula no catálogo</x-consulta.selo>@endunless
                                    </p>
                                @endif
                                @unless ($item->isMatched())<p class="sr-only">Não encontrado no catálogo: vamos conferir</p>@endunless
                                @if ($item->scheduledTimes() !== [] && ! $item->as_needed && ! $item->isDynamic())<p class="sr-only">às {{ implode(', ', $item->scheduledTimes()) }}</p>@endif

                                <x-consulta.opcoes-do-remedio :item="$item" :editando="$editandoId === $item->id" :posologia="$posologia" :receitas="$receitas" />
                            </article>
                        </li>
                    @empty
                        <li><x-consulta.vazio icon="pilula" :titulo="$encerrados->isEmpty() ? 'Nenhum remédio registrado.' : 'Nenhum remédio em uso agora.'" texto="Toque em “Adicionar remédio” ou fotografe uma receita." /></li>
                    @endforelse
                </ul>

                {{-- Histórico: tratamentos que terminaram (data final passou). Saem da lista, dos lembretes e das verificações. --}}
                @if ($encerrados->isNotEmpty())
                    <details class="group rounded-2xl bg-white ring-1 ring-slate-200" @if ($encerrados->contains('id', $editandoId)) open @endif>
                        <summary class="flex min-h-14 cursor-pointer select-none items-center justify-between gap-2 px-4 py-2 font-semibold text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                            <span class="inline-flex min-w-0 items-center gap-2"><x-consulta.icone name="historico" plain class="size-6 shrink-0" />Histórico de tratamentos ({{ $encerrados->count() }})</span>
                            <x-consulta.icone name="seta" plain class="size-5 shrink-0 transition-transform group-open:rotate-90" />
                        </summary>
                        <ul class="grid gap-1 px-2 pb-2" aria-label="Tratamentos encerrados">
                            @foreach ($encerrados as $item)
                                <li wire:key="enc-{{ $item->id }}" class="grid min-w-0 gap-2 rounded-xl px-2 py-2 hover:bg-slate-50" x-data="{ folha: false, confirmarRemover: false }">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <div class="min-w-0 flex-1">
                                            <p class="break-words font-semibold leading-snug text-slate-800">{{ $item->displayName() }}</p>
                                            <p class="break-words text-sm text-slate-600">{{ collect([$item->dosage, 'terminou em '.$item->ends_at?->format('d/m/Y')])->filter()->join(' · ') }}</p>
                                        </div>
                                        <button type="button" class="inline-flex size-11 shrink-0 items-center justify-center rounded-full text-brand-800 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" x-on:click="folha = true; confirmarRemover = false" aria-label="Opções de {{ $item->displayName() }}" aria-haspopup="dialog"><svg class="size-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm6 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm6 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" /></svg></button>
                                    </div>
                                    <x-consulta.opcoes-do-remedio :item="$item" :editando="$editandoId === $item->id" :posologia="$posologia" :receitas="$receitas" encerrado />
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                <div class="grid gap-2 sm:grid-cols-2">
                    @if ($emUso->isNotEmpty())<x-consulta.botao variant="soft" size="md" :href="route('consulta.hoje')"><x-consulta.icone name="relogio" plain class="size-6" />Ver remédios de hoje</x-consulta.botao>@endif
                    @if ($emUso->filter(fn ($item) => $item->isMatched())->count() >= 2)<x-consulta.botao variant="soft" size="md" :href="route('consulta.pesquisa', ['usar' => 1])"><x-consulta.icone name="lupa" plain class="size-6" />Verificar interações entre eles</x-consulta.botao>@endif
                    <x-consulta.botao variant="secondary" size="md" :href="route('consulta.receita.nova')" class="sm:col-span-2"><x-consulta.icone name="camera" plain class="size-6" />Fotografar receita</x-consulta.botao>
                </div>
            </section>

            {{-- Receitas --}}
            <section id="painel-receitas" role="tabpanel" aria-labelledby="tab-receitas" x-show="aba === 'receitas'" x-cloak class="grid gap-3">
                <div class="flex gap-2">
                    <x-consulta.botao variant="accent" size="md" class="min-w-0 flex-[3]" :href="route('consulta.receita.nova')"><x-consulta.icone name="camera" plain class="size-6 shrink-0" />Fotografar receita</x-consulta.botao>
                    <x-consulta.botao variant="secondary" size="md" class="min-w-0 flex-[2]" wire:click="$toggle('mostrarFormularioReceita')" aria-expanded="{{ $mostrarFormularioReceita ? 'true' : 'false' }}" aria-controls="form-receita">{{ $mostrarFormularioReceita ? 'Fechar' : 'Digitar' }}<span class="sr-only"> receita à mão</span></x-consulta.botao>
                </div>
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
                            <article class="grid min-w-0 gap-3 rounded-2xl bg-white p-3 ring-1 ring-slate-200 sm:p-4" aria-labelledby="rc-t-{{ $receita->id }}" x-data="{ confirmar: false }">
                                <div class="flex min-w-0 items-start gap-3">
                                    <x-consulta.icone name="receita" size="sm" class="mt-0.5" :tone="$receita->status === P::STATUS_CONFIRMED ? 'brand' : ($receita->status === P::STATUS_FAILED ? 'danger' : 'warning')" />
                                    <div class="min-w-0 flex-1">
                                        <h3 id="rc-t-{{ $receita->id }}" class="break-words text-lg font-semibold leading-snug text-brand-900">{{ $receita->prescriberDisplayName() }}</h3>
                                        <p class="break-words text-sm text-slate-600">{{ collect([$receita->prescriber_registration, $receita->issued_at?->format('d/m/Y') ?? 'sem data', $receita->source === P::SOURCE_PHOTO ? 'lida por foto' : 'digitada'])->filter()->join(' · ') }}</p>
                                    </div>
                                </div>
                                @if ($receita->status !== P::STATUS_CONFIRMED)
                                    <x-consulta.selo :tone="$receita->status === P::STATUS_FAILED ? 'danger' : 'warning'" class="justify-self-start !text-sm">{{ match ($receita->status) { P::STATUS_REVIEW => 'aguardando sua conferência', P::STATUS_FAILED => 'leitura falhou', default => 'em leitura' } }}</x-consulta.selo>
                                @endif
                                @if ($receita->medications->isNotEmpty())
                                    <ul class="grid divide-y divide-slate-200 rounded-xl bg-slate-50 ring-1 ring-slate-200" aria-label="Remédios desta receita">
                                        @foreach ($receita->medications as $med)
                                            <li class="grid min-w-0 gap-0.5 px-3 py-2">
                                                <p class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                                    <span class="min-w-0 break-words font-semibold leading-snug text-slate-900">{{ $med->displayName() }}</span>
                                                    @unless ($med->isMatched())<x-consulta.selo tone="warning" class="!px-2 !py-0 !text-sm">a conferir</x-consulta.selo>@endunless
                                                    @unless ($med->isActive())<x-consulta.selo tone="neutral" class="!px-2 !py-0 !text-sm">terminou</x-consulta.selo>@endunless
                                                </p>
                                                @if ($med->dosage || $med->frequency)<p class="break-words text-sm leading-snug text-slate-700">{{ collect([$med->dosage, $med->frequency])->filter()->join(', ') }}</p>@endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($receita->notes)<p class="break-words text-base text-slate-700">{{ $receita->notes }}</p>@endif
                                <div class="flex flex-wrap items-center justify-between gap-2" x-show="!confirmar">
                                    @if ($receita->source === P::SOURCE_PHOTO && $receita->status !== P::STATUS_CONFIRMED)
                                        <x-consulta.botao size="sm" class="!w-auto" :href="route('consulta.receita.revisar', $receita)">{{ $receita->status === P::STATUS_REVIEW ? 'Conferir a receita' : 'Abrir' }}</x-consulta.botao>
                                    @endif
                                    <button type="button" class="ml-auto inline-flex min-h-11 items-center gap-1.5 rounded-xl px-3 text-base font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" x-on:click="confirmar = true" aria-label="Remover receita de {{ $receita->prescriber_name }}"><x-consulta.icone name="apagar" plain class="size-5" />Apagar receita</button>
                                </div>
                                <div x-cloak x-show="confirmar" class="grid gap-2 rounded-2xl bg-rose-50 p-3 ring-1 ring-rose-200">
                                    <p class="font-semibold text-rose-900">Apagar esta receita?</p>
                                    <div class="flex gap-2">
                                        <x-consulta.botao variant="danger" size="sm" class="min-w-0 flex-1" wire:click="removerReceita({{ $receita->id }})" wire:loading.attr="disabled">Sim, apagar</x-consulta.botao>
                                        <x-consulta.botao variant="quiet" size="sm" class="min-w-0 flex-1" x-on:click="confirmar = false">Não</x-consulta.botao>
                                    </div>
                                </div>
                            </article>
                        </li>
                    @empty
                        <li><x-consulta.vazio icon="receita" titulo="Nenhuma receita registrada." texto="Fotografe a sua ou digite os dados à mão." /></li>
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
