<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $isWelcome ? 'Primeiro acesso' : 'Nova senha' }} · BULAI</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-50 px-4 py-10 text-slate-900 antialiased">
    <main class="mx-auto grid w-full max-w-lg gap-5">
        <img class="mx-auto h-12 w-auto" src="/brand/bulai-logo-horizontal-transparente.svg" alt="BULAI — Bulas Inteligentes">

        @if ($isWelcome)
            <div class="grid gap-2 text-center">
                <h1 class="text-xl font-semibold text-slate-900">Boas-vindas ao BULAI, {{ $user->name }}</h1>
                <p class="text-sm text-slate-600">O BULAI (Bulas Inteligentes) reúne bulas de medicamentos numa base organizada e conferida com a lista oficial da ANVISA. Antes de começar, defina a sua senha: a senha provisória vale só para este primeiro acesso.</p>
            </div>

            @if ($user->isAdmin())
                <x-knowledge-admin.section title="O que você encontra na área administrativa" description="Estas são as áreas do menu. A explicação de cada uma também aparece ao passar o mouse sobre o item.">
                    <ul class="grid gap-3">
                        @foreach ($areas as $area)
                            <li class="flex items-start gap-3"><svg class="mt-0.5 size-5 shrink-0 text-brand-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="{{ $area['icon'] }}" /></svg><div><p class="text-sm font-medium text-slate-900">{{ $area['label'] }}</p><p class="text-sm text-slate-600">{{ $area['hint'] }}</p></div></li>
                        @endforeach
                    </ul>
                </x-knowledge-admin.section>
            @else
                <x-knowledge-admin.section title="O que você já pode fazer">
                    <ul class="grid list-disc gap-1 pl-5 text-sm text-slate-700">
                        <li>Ver os seus dados de acesso (nome, e-mail e perfil) em Minha conta.</li>
                        <li>Trocar a sua senha sempre que quiser, também em Minha conta.</li>
                        <li>Encerrar a sessão pelo botão Sair.</li>
                    </ul>
                    <x-knowledge-admin.alert>A consulta de bulas ainda está em preparação e será liberada no seu acesso assim que estiver pronta.</x-knowledge-admin.alert>
                </x-knowledge-admin.section>
            @endif
        @else
            <div class="grid gap-2 text-center">
                <h1 class="text-xl font-semibold text-slate-900">Defina sua nova senha</h1>
                <p class="text-sm text-slate-600">Um administrador gerou uma senha provisória para a sua conta. Para continuar, defina uma senha própria.</p>
            </div>
        @endif

        <form method="POST" action="{{ route('first-access.password.update') }}">
            @csrf
            @method('PUT')
            <x-knowledge-admin.section title="Sua senha" description="Use pelo menos 12 caracteres, com letras e números, diferente da senha provisória. As outras sessões abertas com a sua conta serão encerradas.">
                <x-knowledge-admin.input label="Nova senha" type="password" name="password" autocomplete="new-password" required />
                <x-knowledge-admin.input label="Confirme a nova senha" type="password" name="password_confirmation" autocomplete="new-password" required />
                <div><x-knowledge-admin.button type="submit">Salvar senha e continuar</x-knowledge-admin.button></div>
            </x-knowledge-admin.section>
        </form>

        <div class="flex justify-end">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-knowledge-admin.button variant="secondary" type="submit">Sair</x-knowledge-admin.button>
            </form>
        </div>
    </main>
</body>
</html>
