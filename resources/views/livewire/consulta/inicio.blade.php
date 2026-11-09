@php
    // Cartão do dia + ações grandes, uma por linha. Nada mais na tela (sênior-primeiro).
    $acoes = array_values(array_filter([
        ['route' => route('consulta.perguntar'), 'title' => 'Perguntar sobre um remédio', 'text' => 'Escreva ou fale sua dúvida', 'icon' => 'pergunta', 'tone' => 'accent', 'destaque' => true],
        ['route' => route('consulta.hoje'), 'title' => 'Remédios de hoje', 'text' => $proximaDose, 'icon' => 'relogio', 'tone' => $doseAtrasada ? 'danger' : 'brand'],
        $receitaPorFoto ? ['route' => route('consulta.receita.nova'), 'title' => 'Fotografar minha receita', 'text' => 'O app lê os remédios para você conferir', 'icon' => 'camera', 'tone' => 'success'] : null,
        ['route' => route('consulta.perfil'), 'title' => 'Meus remédios', 'text' => 'Receitas, alergias e o que você toma', 'icon' => 'remedios', 'tone' => 'brand'],
        ['route' => route('consulta.historico'), 'title' => 'Ver o que já perguntei', 'text' => 'Suas perguntas e consultas anteriores', 'icon' => 'historico', 'tone' => 'neutral'],
    ]));
@endphp
<x-consulta.shell title="Olá{{ $nome !== '' ? ', '.$nome : '' }}" :conta="true">
    <div class="-mt-1 flex flex-wrap items-center justify-between gap-2 px-1">
        <p class="text-lg text-slate-700">{{ $dataHoje }}</p>
        @if ($naoLidos > 0)
            <a href="{{ route('consulta.avisos') }}" wire:navigate class="inline-flex min-h-11 items-center gap-2 rounded-full bg-accent-100 px-4 text-base font-semibold text-accent-800 ring-1 ring-inset ring-accent-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600"><x-consulta.icone name="sino" plain class="size-5" />{{ $naoLidos }} {{ $naoLidos === 1 ? 'aviso novo' : 'avisos novos' }}</a>
        @endif
    </div>

    {{-- Cartão do dia: quantas doses já foram, qual é a próxima e "Tomei" em um toque (só quando está na hora). --}}
    @if ($consentido && $total > 0)
        <section class="grid gap-4 rounded-3xl bg-brand-700 p-5 text-white shadow-md" aria-labelledby="dia-titulo">
            <div class="flex items-center gap-4">
                <x-consulta.anel-progresso :feitos="$tomadas" :total="$total" rotulo="doses" tone="text-accent-400" class="rounded-full bg-white/95" />
                <div class="min-w-0 flex-1">
                    <h2 id="dia-titulo" class="text-base font-semibold uppercase tracking-wide text-brand-100">Hoje</h2>
                    <p class="break-words text-2xl font-semibold leading-tight">{{ $tomadas === $total ? 'Tudo tomado por hoje!' : $tomadas.' de '.$total.' '.($total === 1 ? 'dose tomada' : 'doses tomadas') }}</p>
                    @if ($proxima !== null)
                        <p class="mt-1 break-words text-lg {{ $doseAtrasada ? 'font-semibold text-accent-200' : 'text-brand-100' }}">{{ $proximaDose }}</p>
                    @endif
                </div>
            </div>
            @if ($podeMarcar)
                <x-consulta.botao variant="accent" wire:click="tomei({{ $proxima['medication']->id }}, '{{ $proxima['scheduled_for']->utc()->toIso8601String() }}')" loadingTarget="tomei" loading="Anotando…"><x-consulta.icone name="check" plain class="size-7" />Marcar como tomado</x-consulta.botao>
            @endif
        </section>
    @endif

    <nav class="grid gap-3" aria-label="Ações principais">
        @foreach ($acoes as $acao)
            <a href="{{ $acao['route'] }}" wire:navigate class="flex min-h-20 min-w-0 items-center gap-4 rounded-2xl px-4 py-4 shadow-sm ring-1 transition active:scale-[0.98] focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40 {{ ($acao['destaque'] ?? false) ? 'bg-accent-600 text-white ring-accent-700 hover:bg-accent-700' : 'bg-white text-slate-900 ring-slate-200 hover:bg-brand-50' }}">
                <x-consulta.icone :name="$acao['icon']" :tone="($acao['destaque'] ?? false) ? 'white' : $acao['tone']" size="lg" />
                <span class="min-w-0 flex-1"><span class="block break-words text-xl font-semibold leading-tight">{{ $acao['title'] }}</span><span class="block break-words text-base {{ ($acao['destaque'] ?? false) ? 'text-white/90' : ($acao['tone'] === 'danger' ? 'font-semibold text-rose-800' : 'text-slate-600') }}">{{ $acao['text'] }}</span></span>
                <x-consulta.icone name="seta" plain class="size-6 shrink-0 opacity-60" />
            </a>
        @endforeach
    </nav>

    @if ($cuidados->isNotEmpty())
        <section class="grid gap-3" aria-labelledby="cuidados-titulo">
            <h2 id="cuidados-titulo" class="px-1 text-xl font-semibold text-brand-900">Quem você acompanha</h2>
            @foreach ($cuidados as $cuidado)
                <a href="{{ route('consulta.cuidando.hoje', $cuidado) }}" wire:navigate wire:key="cuidado-{{ $cuidado->id }}" class="flex min-h-20 min-w-0 items-center gap-4 rounded-2xl bg-white px-4 py-4 text-slate-900 shadow-sm ring-1 ring-accent-300 transition hover:bg-accent-50 active:scale-[0.98] focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40">
                    <span class="inline-flex size-14 shrink-0 items-center justify-center rounded-full bg-accent-100 text-2xl font-semibold text-accent-800" aria-hidden="true">{{ mb_strtoupper(mb_substr($cuidado->patient->name, 0, 1)) }}</span>
                    <span class="min-w-0 flex-1"><span class="block break-words text-xl font-semibold leading-tight">Remédios de {{ $cuidado->patient->name }}</span><span class="block break-words text-base text-slate-600">Ver os horários de hoje</span></span>
                    <x-consulta.icone name="seta" plain class="size-6 shrink-0 opacity-60" />
                </a>
            @endforeach
        </section>
    @endif

    <x-consulta.instalar />
</x-consulta.shell>
