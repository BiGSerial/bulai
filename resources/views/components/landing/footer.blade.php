@props(['links' => []])
<footer class="border-t border-brand-100 bg-surface-100" aria-labelledby="rodape-titulo">
    <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 md:grid-cols-[1.4fr_1fr_1fr]">
        <div class="grid min-w-0 content-start gap-4">
            <h2 id="rodape-titulo" class="sr-only">Rodapé</h2>
            <img class="h-10 w-auto max-w-full" src="/brand/bulai-logo-horizontal-transparente.svg" alt="BULA-IA — Bulas Inteligentes" width="180" height="40">
            <p class="max-w-md text-base leading-relaxed text-slate-700">Bulas em linguagem simples, remédios de hoje com lembretes e respostas baseadas nas bulas oficiais. Feito para quem toma remédio todo dia e para quem cuida.</p>
            <p class="text-sm text-slate-600">Projeto acadêmico — Trabalho de Conclusão de Curso (TCC). Protótipo com acesso limitado, mediante aprovação. Uso informativo; não substitui médico ou farmacêutico.</p>
        </div>
        <nav class="grid min-w-0 content-start gap-2" aria-label="Seções (rodapé)">
            <p class="text-sm font-bold uppercase tracking-wider text-brand-900">Navegue</p>
            @foreach ($links as $href => $label)
                <a class="w-fit rounded py-1 text-base text-slate-700 hover:text-brand-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ $href }}">{{ $label }}</a>
            @endforeach
        </nav>
        <nav class="grid min-w-0 content-start gap-2" aria-label="Acesso">
            <p class="text-sm font-bold uppercase tracking-wider text-brand-900">Acesso</p>
            @auth
                <a class="w-fit rounded py-1 text-base text-slate-700 hover:text-brand-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route(auth()->user()->homeRouteName()) }}">Abrir o aplicativo</a>
            @else
                <a class="w-fit rounded py-1 text-base text-slate-700 hover:text-brand-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('login') }}">Entrar</a>
                <a class="w-fit rounded py-1 text-base text-slate-700 hover:text-brand-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('register') }}">Criar conta (sujeita a aprovação)</a>
                <a class="w-fit rounded py-1 text-base text-slate-700 hover:text-brand-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('password.request') }}">Esqueci minha senha</a>
            @endauth
            <a class="w-fit rounded py-1 text-base text-slate-700 hover:text-brand-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="#seguranca">Sobre o BULA-IA e seus limites</a>
        </nav>
    </div>
    <div class="border-t border-brand-100/70">
        <p class="mx-auto max-w-6xl px-4 py-5 text-sm text-slate-600">© {{ now()->year }} BULA-IA · Fontes: catálogo de medicamentos e bulas da ANVISA, base de interações DDInter (uso acadêmico) e, como complemento traduzido, bulas da FDA via openFDA e DailyMed (U.S. National Library of Medicine).</p>
    </div>
</footer>
