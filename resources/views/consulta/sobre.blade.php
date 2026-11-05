<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#12344d">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/brand/bulai-icone-app.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/brand/bulai-icone-180.png" sizes="180x180">
    <title>Sobre o BULA-IA</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-surface-50 text-slate-950 antialiased">
    <x-consulta.shell title="Sobre o BULA-IA" :back="route('consulta.inicio')">
        <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-2xl font-semibold text-brand-900">O BULA-IA não substitui seu médico ou farmacêutico.</h2>
            <p class="text-slate-800">{{ config('bulai.guidelines.disclaimer') }}</p>
        </section>
        <section class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-2xl font-semibold text-brand-900">Como o aplicativo funciona</h2>
            <ul class="grid gap-3 text-slate-800">
                <li class="flex gap-3"><span class="mt-1 size-3 shrink-0 rounded-full bg-accent-500" aria-hidden="true"></span><span>As respostas vêm <strong>só das bulas oficiais</strong> e das bases de interações cadastradas. Se a bula não responde, o aplicativo diz que não sabe.</span></li>
                <li class="flex gap-3"><span class="mt-1 size-3 shrink-0 rounded-full bg-accent-500" aria-hidden="true"></span><span>Cada frase é conferida automaticamente contra o texto da bula antes de aparecer para você.</span></li>
                <li class="flex gap-3"><span class="mt-1 size-3 shrink-0 rounded-full bg-accent-500" aria-hidden="true"></span><span>Se você registrar seus remédios e alergias, o aplicativo avisa quando um remédio pode interagir com o que você já usa.</span></li>
                <li class="flex gap-3"><span class="mt-1 size-3 shrink-0 rounded-full bg-accent-500" aria-hidden="true"></span><span>Só você vê seus dados de saúde. Você pode apagar tudo a qualquer momento em "Meus remédios".</span></li>
                <li class="flex gap-3"><span class="mt-1 size-3 shrink-0 rounded-full bg-accent-500" aria-hidden="true"></span><span>"Nenhuma interação encontrada" não quer dizer que a combinação é segura. Na dúvida, fale com seu médico ou farmacêutico.</span></li>
            </ul>
        </section>
        <x-slot:sticky>
            <x-knowledge-admin.button :href="route('consulta.inicio')" class="min-h-14 w-full text-lg">Voltar ao início</x-knowledge-admin.button>
        </x-slot:sticky>
    </x-consulta.shell>
    @livewireScripts
</body>
</html>
