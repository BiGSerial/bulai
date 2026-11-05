@extends('layouts.auth')
@section('title', 'Minha conta')
@section('width', 'lg')
@section('heading', 'Minha conta')
@section('lead', 'Seus dados de acesso, senha e sessões.')
@section('content')
    <x-knowledge-admin.section title="Dados de acesso">
        <x-knowledge-admin.facts>
            <x-knowledge-admin.fact label="Nome">{{ $user->name }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Perfil">{{ $user->role->label() }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact class="sm:col-span-2" label="E-mail">{{ $user->email }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Último acesso">{{ $user->last_login_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? 'Este é o primeiro' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Endereço IP do último acesso">{{ $user->last_login_ip ?? 'Não registrado' }}</x-knowledge-admin.fact>
        </x-knowledge-admin.facts>
        <p class="text-sm text-slate-600">Não reconhece o último acesso? Troque a senha e encerre as outras sessões abaixo. <a class="font-medium text-brand-700 underline-offset-2 hover:underline" href="{{ route('account.history') }}">Ver histórico da conta</a></p>
    </x-knowledge-admin.section>

    <form method="POST" action="{{ route('account.password.update') }}" novalidate>
        @csrf
        @method('PUT')
        <x-knowledge-admin.section title="Trocar senha" description="Use pelo menos 12 caracteres, com letras e números. As outras sessões abertas com a sua conta serão encerradas.">
            <x-knowledge-admin.input label="Senha atual" type="password" name="current_password" autocomplete="current-password" required />
            <x-knowledge-admin.input label="Nova senha" type="password" name="password" autocomplete="new-password" required />
            <x-knowledge-admin.input label="Confirme a nova senha" type="password" name="password_confirmation" autocomplete="new-password" required />
            <div><x-knowledge-admin.button type="submit" class="min-h-11 w-full sm:w-auto">Salvar nova senha</x-knowledge-admin.button></div>
        </x-knowledge-admin.section>
    </form>

    <form method="POST" action="{{ route('account.sessions.terminate') }}" novalidate>
        @csrf
        <x-knowledge-admin.section title="Sair de outros dispositivos" description="Encerra as sessões abertas com a sua conta em outros navegadores e aparelhos. Esta sessão continua conectada.">
            <x-knowledge-admin.input label="Senha atual" type="password" name="current_password" wrapper-class="sm:max-w-sm" autocomplete="current-password" required />
            <div><x-knowledge-admin.button variant="secondary" type="submit" class="min-h-11 w-full sm:w-auto">Encerrar outras sessões</x-knowledge-admin.button></div>
        </x-knowledge-admin.section>
    </form>

    <div class="flex flex-wrap items-center justify-end gap-3">
        @if ($user->isAdmin())
            <a class="mr-auto text-sm font-medium text-brand-700 transition hover:text-brand-800" href="{{ route('admin.knowledge.dashboard') }}">← Ir para a área administrativa</a>
        @else
            <a class="mr-auto text-sm font-medium text-brand-700 transition hover:text-brand-800" href="{{ route('consulta.inicio') }}">← Voltar ao aplicativo</a>
        @endif
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-knowledge-admin.button variant="secondary" type="submit">Sair</x-knowledge-admin.button>
        </form>
    </div>
@endsection
