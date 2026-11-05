<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#12344d">
    <link rel="icon" href="/brand/bulai-icone-app.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/brand/bulai-icone-180.png" sizes="180x180">
    <title>@yield('title') · BULAI</title>
    @vite(['resources/css/app.css'])
</head>
{{-- Páginas de acesso (login, senha, primeiro acesso, Minha conta): texto grande, um cartão, uma ação por linha. --}}
@php($wide = trim($__env->yieldContent('width', 'sm')) === 'lg')
<body class="min-h-screen bg-surface-100 text-lg leading-relaxed text-slate-900 antialiased">
    <a class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-2 focus:z-30 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2 focus:font-medium focus:text-brand-800 focus:shadow-sm" href="#conteudo">Ir para o conteúdo</a>
    <div class="mx-auto flex min-h-screen w-full flex-col px-4 py-8 sm:py-12 {{ $wide ? 'max-w-lg' : 'max-w-md' }}">
        <header class="mb-6 grid justify-items-center gap-4 text-center">
            <a href="{{ url('/') }}" aria-label="BULA-IA — página inicial"><img class="h-14 w-auto sm:h-16" src="/brand/bulai-logo-horizontal-transparente.svg" alt="BULA-IA — Bulas Inteligentes"></a>
            @hasSection('heading')
                <div class="grid gap-2">
                    <h1 class="text-2xl font-semibold text-brand-900 sm:text-3xl">@yield('heading')</h1>
                    @hasSection('lead')<p class="text-slate-700">@yield('lead')</p>@endif
                </div>
            @endif
        </header>
        <main id="conteudo" class="grid gap-5">
            @if (session('status'))<x-knowledge-admin.alert tone="success" role="status">{{ session('status') }}</x-knowledge-admin.alert>@endif
            @if (session('accountFeedback'))<x-knowledge-admin.alert tone="success" role="status">{{ session('accountFeedback') }}</x-knowledge-admin.alert>@endif
            @yield('content')
        </main>
        <footer class="mt-auto grid gap-2 pt-8 text-center text-base text-slate-700">
            @yield('footer')
            <p>O BULA-IA não substitui seu médico ou farmacêutico.</p>
        </footer>
    </div>
</body>
</html>
