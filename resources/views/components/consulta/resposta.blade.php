@props(['resposta', 'titulo' => null, 'mostrarPergunta' => true])
@php
    use App\Services\Patient\MedicationQuestionService as Q;
    $status = $resposta['status'];
    $evidencias = $resposta['evidences'] ?? [];
    // Fontes = trechos de bula; o contexto do tratamento e a conversa anterior não são "fontes" para o paciente.
    $fontes = collect($evidencias)->filter(fn ($e) => ! in_array($e['document_type'] ?? null, ['treatment_context', 'conversation_context'], true));
    $usouTratamento = collect($evidencias)->contains(fn ($e) => ($e['document_type'] ?? null) === 'treatment_context' && ! empty($e['supports_claims']));
    $usouConversa = (bool) ($resposta['generation']['conversation_context'] ?? false);
    $texto = match ($status) {
        Q::ANSWERED, Q::NEEDS_INFO => $resposta['answer'],
        Q::UNAVAILABLE => 'Não consegui consultar o assistente nessa hora.',
        Q::EXCERPTS_ONLY => 'Não consegui montar uma resposta curta com segurança, mas achei na bula estas partes que falam disso. Na dúvida, pergunte ao seu farmacêutico.',
        Q::NOT_IDENTIFIED => 'Conheço o remédio, mas ainda não tenho a bula dele comigo. Você confere na bula que vem na caixa ou pergunta ao farmacêutico. A equipe foi avisada para buscar a bula.',
        Q::NO_LEAFLET => 'Ainda não tenho a bula deste remédio comigo. Você confere na bula que vem na caixa ou pergunta ao farmacêutico. A equipe foi avisada para buscar a bula.',
        default => 'Procurei na bula '.(! empty($resposta['retrieval']['fallback_consulted']) ? 'inteira ' : '').'e não localizei uma informação suficientemente clara sobre isso. Prefiro não completar a resposta com suposições; isso não quer dizer que seja seguro. Na dúvida, fale com um farmacêutico ou médico.',
    };
@endphp
<section {{ $attributes->class('grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm') }} aria-live="polite" aria-labelledby="resp-titulo" x-data="{ podeOuvir: 'speechSynthesis' in window, falando: false }">
    <h2 id="resp-titulo" class="text-base font-semibold uppercase tracking-wide text-slate-600">{{ $titulo ?? 'Resposta' }}</h2>
    @if ($mostrarPergunta && ! empty($resposta['pergunta']))<p class="text-base italic text-slate-600">“{{ $resposta['pergunta'] }}”</p>@endif

    {{-- 1. A resposta em letras grandes vem primeiro. --}}
    <p class="whitespace-pre-line text-xl leading-relaxed text-slate-900 sm:text-2xl" x-ref="texto">{{ $texto }}</p>

    {{-- 2. Ouvir (só se o navegador souber falar). --}}
    <button type="button" x-cloak x-show="podeOuvir" class="inline-flex min-h-14 w-full items-center justify-center gap-2 rounded-xl border-2 border-brand-700 bg-white px-4 text-lg font-semibold text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40"
        x-on:click="if (falando) { speechSynthesis.cancel(); falando = false; return } const u = new SpeechSynthesisUtterance($refs.texto.textContent.trim()); u.lang = 'pt-BR'; u.rate = 0.95; u.onend = () => falando = false; speechSynthesis.cancel(); speechSynthesis.speak(u); falando = true">
        <svg class="size-7" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.114 5.636a9 9 0 0 1 0 12.728M16.463 8.288a5.25 5.25 0 0 1 0 7.424M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" /></svg>
        <span x-text="falando ? 'Parar de ouvir' : 'Ouvir a resposta'">Ouvir a resposta</span>
    </button>

    @if (in_array($status, [Q::ANSWERED, Q::NEEDS_INFO], true))
        <p class="text-base text-slate-700">Li isso na bula oficial.@if ($usouTratamento) Também olhei os remédios e as alergias do seu perfil.@endif @if ($usouConversa) Leva em conta o que você perguntou antes nesta conversa.@endif</p>
    @endif

    @if ($status === Q::EXCERPTS_ONLY && $fontes->isNotEmpty())
        <ol class="grid gap-3" aria-label="Partes da bula">
            @foreach ($fontes->take(2) as $evidence)
                <li class="grid gap-1">
                    <p class="text-base font-semibold text-slate-700">{{ $evidence['document_title'] }}</p>
                    <blockquote class="max-h-72 overflow-y-auto whitespace-pre-line rounded-lg bg-slate-50 px-3 py-2 text-base leading-relaxed text-slate-800">{{ $evidence['excerpt'] }}</blockquote>
                </li>
            @endforeach
        </ol>
    @elseif ($fontes->isNotEmpty())
        <details class="rounded-xl bg-slate-50 ring-1 ring-slate-200">
            <summary class="min-h-12 cursor-pointer select-none px-4 py-3 text-base font-semibold text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">Ver na bula</summary>
            <ul class="grid gap-3 border-t border-slate-200 px-4 py-3 text-base text-slate-700">
                @foreach ($fontes as $fonte)
                    <li class="grid gap-1 border-l-4 border-brand-200 pl-3"><span class="font-semibold text-slate-600">{{ $fonte['document_title'] }}</span><span class="max-h-48 overflow-y-auto whitespace-pre-line break-words leading-relaxed text-slate-800">{{ $fonte['excerpt'] ?? '' }}</span></li>
                @endforeach
            </ul>
        </details>
    @endif
    {{ $slot ?? '' }}
</section>
