<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar · BULAI</title>
    @vite(['resources/css/app.css'])
</head>
<body class="grid min-h-screen place-items-center bg-slate-50 px-4 text-slate-900 antialiased">
    <main class="w-full max-w-sm">
        <form method="POST" action="{{ route('login.store') }}">
            @csrf
            <x-knowledge-admin.card class="grid gap-4 sm:p-7">
                <img class="mx-auto h-12 w-auto" src="/brand/bulai-logo-horizontal-transparente.svg" alt="BULAI — Bulas Inteligentes">
                <h1 class="text-center text-xl font-semibold text-slate-900">Área administrativa</h1>

                @error('email')<x-knowledge-admin.alert tone="danger">{{ $message }}</x-knowledge-admin.alert>@enderror

                <x-knowledge-admin.input label="E-mail" type="email" name="email" :value="old('email')" autocomplete="email" required autofocus />
                <x-knowledge-admin.input label="Senha" type="password" name="password" autocomplete="current-password" required />
                <label class="flex items-center gap-2 text-sm text-slate-700"><input class="rounded border-slate-300 text-brand-700 focus:ring-brand-600" name="remember" type="checkbox" value="1">Manter conectado</label>
                <x-knowledge-admin.button type="submit">Entrar</x-knowledge-admin.button>
            </x-knowledge-admin.card>
        </form>
    </main>
</body>
</html>
