<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#12344d">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/brand/bulai-icone-app.svg" type="image/svg+xml">
    <link rel="icon" href="/brand/bulai-icone-192.png" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" href="/brand/bulai-icone-180.png" sizes="180x180">
    <title>BULA-IA · Consulta de interações medicamentosas</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-surface-50 px-4 py-8 text-lg leading-relaxed text-slate-900 antialiased">
    <main class="mx-auto grid max-w-2xl gap-6">
        <img class="mx-auto h-14 w-auto" src="/brand/bulai-logo-horizontal-transparente.svg" alt="BULA-IA — Bulas Inteligentes">
        <section class="grid gap-3 text-center">
            <h1 class="text-3xl font-semibold leading-tight text-brand-900 sm:text-4xl">Entenda seus remédios de um jeito simples</h1>
            <p class="text-slate-800">Tire dúvidas, veja a bula em linguagem fácil e saiba se seus remédios combinam. Tudo baseado nas bulas oficiais.</p>
            <div class="mx-auto flex flex-wrap justify-center gap-3 pt-2">
                @auth
                    <x-knowledge-admin.button variant="accent" :href="route(auth()->user()->homeRouteName())" class="min-h-16 w-full px-8 text-xl sm:w-auto">Abrir o aplicativo</x-knowledge-admin.button>
                @else
                    <x-knowledge-admin.button variant="accent" :href="route('login')" class="min-h-16 w-full px-8 text-xl sm:w-auto">Entrar</x-knowledge-admin.button>
                @endauth
            </div>
        </section>
        <section class="grid gap-3 sm:grid-cols-2" aria-label="O que você pode fazer">
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200"><p class="text-xl font-semibold text-brand-900">Bula humanizada</p><p class="mt-1 text-slate-800">Para que serve, como usar, benefícios, riscos e efeitos colaterais, em linguagem simples.</p></div>
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200"><p class="text-xl font-semibold text-brand-900">Interações classificadas</p><p class="mt-1 text-slate-800">Combine dois ou mais medicamentos e veja a gravidade registrada nas bases farmacológicas.</p></div>
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200"><p class="text-xl font-semibold text-brand-900">Seu tratamento e alergias</p><p class="mt-1 text-slate-800">Registre receitas, medicamentos em uso e alergias; o app avisa sobre riscos ao consultar um remédio.</p></div>
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200"><p class="text-xl font-semibold text-brand-900">Perguntas com evidência</p><p class="mt-1 text-slate-800">Tire dúvidas e receba respostas baseadas nas bulas cadastradas, conferidas automaticamente.</p></div>
        </section>
        <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-amber-900" aria-labelledby="limites">
            <h2 id="limites" class="text-xl font-semibold">Limites desta ferramenta</h2>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                <li>{{ config('bulai.guidelines.disclaimer') }}</li>
                <li>Cobre apenas medicamentos do catálogo da ANVISA e as interações presentes nas fontes cadastradas.</li>
                <li>“Nenhuma interação encontrada” <strong>não</strong> significa que a combinação seja segura.</li>
                <li>Explicações geradas por IA são conferidas contra os trechos de bula, mas podem conter imprecisões; confira sempre a fonte.</li>
            </ul>
        </section>
    </main>
</body>
</html>
