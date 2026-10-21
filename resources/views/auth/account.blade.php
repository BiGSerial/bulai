<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Minha conta · BULAI</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-50 px-4 py-10 text-slate-900 antialiased">
    <main class="mx-auto grid w-full max-w-lg gap-5">
        <img class="mx-auto h-12 w-auto" src="/brand/bulai-logo-horizontal-transparente.svg" alt="BULAI — Bulas Inteligentes">
        <h1 class="text-center text-xl font-semibold text-slate-900">Minha conta</h1>

        @if (session('accountFeedback'))<x-knowledge-admin.alert tone="success">{{ session('accountFeedback') }}</x-knowledge-admin.alert>@endif

        <x-knowledge-admin.section title="Dados de acesso">
            <x-knowledge-admin.facts>
                <x-knowledge-admin.fact label="Nome">{{ $user->name }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Perfil">{{ $user->role->label() }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact class="sm:col-span-2" label="E-mail">{{ $user->email }}</x-knowledge-admin.fact>
            </x-knowledge-admin.facts>
        </x-knowledge-admin.section>

        <form method="POST" action="{{ route('account.password.update') }}">
            @csrf
            @method('PUT')
            <x-knowledge-admin.section title="Trocar senha" description="Use pelo menos 12 caracteres, com letras e números. As outras sessões abertas com a sua conta serão encerradas.">
                <x-knowledge-admin.input label="Senha atual" type="password" name="current_password" autocomplete="current-password" required />
                <x-knowledge-admin.input label="Nova senha" type="password" name="password" autocomplete="new-password" required />
                <x-knowledge-admin.input label="Confirme a nova senha" type="password" name="password_confirmation" autocomplete="new-password" required />
                <div><x-knowledge-admin.button type="submit">Salvar nova senha</x-knowledge-admin.button></div>
            </x-knowledge-admin.section>
        </form>

        <div class="flex flex-wrap items-center justify-end gap-3">
            @if ($user->isAdmin())
                <a class="mr-auto text-sm font-medium text-brand-700 transition hover:text-brand-800" href="{{ route('admin.knowledge.dashboard') }}">← Ir para a área administrativa</a>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-knowledge-admin.button variant="secondary" type="submit">Sair</x-knowledge-admin.button>
            </form>
        </div>
    </main>
</body>
</html>
