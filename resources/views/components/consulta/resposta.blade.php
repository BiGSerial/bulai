@props(['resposta', 'titulo' => null])
@php
    use App\Services\Patient\MedicationQuestionService as Q;
    $status = $resposta['status'];
    $evidencias = $resposta['evidences'] ?? [];
    $fontes = collect($evidencias)->filter(fn ($e) => ($e['document_type'] ?? null) !== 'treatment_context');
    $usouTratamento = collect($evidencias)->contains(fn ($e) => ($e['document_type'] ?? null) === 'treatment_context' && ! empty($e['supports_claims']));
    $texto = match ($status) {
        Q::ANSWERED => $resposta['answer'],
        Q::EXCERPTS_ONLY => 'Não conseguimos montar uma resposta simples com segurança. Abaixo estão os trechos da bula mais próximos da sua pergunta. Na dúvida, fale com seu médico ou farmacêutico.',
        Q::NOT_IDENTIFIED => 'Não encontramos a substância deste remédio no catálogo, então não conseguimos procurar a bula certa.',
        Q::NO_LEAFLET => 'Ainda não temos a bula deste remédio. Não respondemos “de memória”: veja a bula da embalagem ou pergunte ao farmacêutico.',
        default => 'A bula cadastrada não responde a essa pergunta. Isso não quer dizer que a resposta seja “não”: veja a bula completa ou fale com um profissional.',
    };
@endphp
<section {{ $attributes->class('grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm') }} aria-live="polite" aria-labelledby="resp-titulo" x-data="{ podeOuvir: 'speechSynthesis' in window, falando: false }">
    <h2 id="resp-titulo" class="text-base font-semibold uppercase tracking-wide text-slate-600">{{ $titulo ?? 'Resposta' }}</h2>
    @if (! empty($resposta['pergunta']))<p class="text-base italic text-slate-600">“{{ $resposta['pergunta'] }}”</p>@endif

    {{-- 1. A resposta em letras grandes vem primeiro. --}}
    <p class="text-xl leading-relaxed text-slate-900 sm:text-2xl" x-ref="texto">{{ $texto }}</p>

    {{-- 2. Ouvir (só se o navegador souber falar). --}}
    <button type="button" x-cloak x-show="podeOuvir" class="inline-flex min-h-14 w-full items-center justify-center gap-2 rounded-xl border-2 border-brand-700 bg-white px-4 text-lg font-semibold text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40"
        x-on:click="if (falando) { speechSynthesis.cancel(); falando = false; return } const u = new SpeechSynthesisUtterance($refs.texto.textContent.trim()); u.lang = 'pt-BR'; u.rate = 0.95; u.onend = () => falando = false; speechSynthesis.cancel(); speechSynthesis.speak(u); falando = true">
        <svg class="size-7" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.114 5.636a9 9 0 0 1 0 12.728M16.463 8.288a5.25 5.25 0 0 1 0 7.424M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" /></svg>
        <span x-text="falando ? 'Parar de ouvir' : 'Ouvir a resposta'">Ouvir a resposta</span>
    </button>

    @if ($status === Q::ANSWERED)
        <p class="text-base text-slate-700">Baseado na bula oficial, conferido automaticamente.@if ($usouTratamento) Considera os medicamentos e alergias do seu perfil.@endif</p>
    @endif

    @if ($status === Q::EXCERPTS_ONLY && $fontes->isNotEmpty())
        <ol class="grid gap-3" aria-label="Trechos das bulas">
            @foreach ($fontes as $evidence)
                <li class="grid gap-1">
                    <p class="text-base text-slate-700"><span class="font-semibold text-slate-900">{{ $evidence['document_title'] }}</span> · {{ $evidence['section'] ?? 'seção não identificada' }} · página {{ $evidence['page_start'] ?? '?' }}</p>
                    <blockquote class="max-h-72 overflow-y-auto whitespace-pre-line rounded-lg bg-slate-50 px-3 py-2 text-base leading-relaxed text-slate-800">{{ $evidence['excerpt'] }}</blockquote>
                </li>
            @endforeach
        </ol>
    @elseif ($fontes->isNotEmpty())
        <details class="rounded-xl bg-slate-50 ring-1 ring-slate-200">
            <summary class="min-h-12 cursor-pointer select-none px-4 py-3 text-base font-semibold text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">De onde veio esta resposta?</summary>
            <ul class="grid gap-1 border-t border-slate-200 px-4 py-3 text-base text-slate-700">
                @foreach ($fontes as $fonte)
                    <li>{{ $fonte['document_title'] }} · {{ $fonte['section'] ?? 'seção não identificada' }} · página {{ $fonte['page_start'] ?? '?' }}</li>
                @endforeach
            </ul>
        </details>
    @endif
    {{ $slot ?? '' }}
</section>
