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
        <section class="grid gap-3 rounded-3xl bg-brand-700 p-5 text-white shadow-md">
            <div class="flex items-start gap-3">
                <x-consulta.icone name="escudo" tone="white" size="lg" />
                <div class="min-w-0">
                    <h2 class="text-2xl font-semibold leading-tight">O BULA-IA não substitui seu médico ou farmacêutico.</h2>
                    <p class="mt-2 text-brand-100">{{ config('bulai.guidelines.disclaimer') }}</p>
                </div>
            </div>
        </section>
        <x-consulta.cartao>
            <h2 class="text-2xl font-semibold text-brand-900">Como o aplicativo funciona</h2>
            <ul class="grid gap-4 text-slate-800">
                @foreach ([
                    ['bula', 'As respostas vêm <strong>só das bulas oficiais</strong> e das bases de interações cadastradas. Se a bula não responde, o aplicativo diz que não sabe.'],
                    ['check', 'Cada frase é conferida automaticamente contra o texto da bula antes de aparecer para você.'],
                    ['alerta', 'Se você registrar seus remédios e alergias, o aplicativo avisa quando um remédio pode interagir com o que você já usa.'],
                    ['escudo', 'Só você vê seus dados de saúde. Você pode apagar tudo a qualquer momento em "Meus remédios".'],
                    ['info', '"Nenhuma interação encontrada" não quer dizer que a combinação é segura. Na dúvida, fale com seu médico ou farmacêutico.'],
                ] as [$icone, $texto])
                    <li class="flex min-w-0 items-start gap-3"><x-consulta.icone :name="$icone" size="sm" /><span class="min-w-0 break-words">{!! $texto !!}</span></li>
                @endforeach
            </ul>
        </x-consulta.cartao>
        <x-slot:sticky>
            <x-consulta.botao :href="route('consulta.inicio')">Voltar ao início</x-consulta.botao>
        </x-slot:sticky>
    </x-consulta.shell>
    @livewireScripts
</body>
</html>
