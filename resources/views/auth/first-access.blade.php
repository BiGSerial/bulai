@extends('layouts.auth')
@section('title', $isWelcome ? 'Primeiro acesso' : 'Nova senha')
@section('width', 'lg')
@section('heading', $isWelcome ? 'Boas-vindas ao BULAI, '.$user->name : 'Defina sua nova senha')
@section('lead', $isWelcome
    ? 'O BULAI (Bulas Inteligentes) reúne bulas de medicamentos numa base organizada e conferida com a lista oficial da ANVISA. Antes de começar, defina a sua senha: a senha provisória vale só para este primeiro acesso.'
    : 'Um administrador gerou uma senha provisória para a sua conta. Para continuar, defina uma senha própria.')
@section('content')
    @if ($isWelcome)
        @if ($user->isAdmin())
            <x-knowledge-admin.section title="O que você encontra na área administrativa" description="Estas são as áreas do menu. A explicação de cada uma também aparece ao passar o mouse sobre o item.">
                <ul class="grid gap-3 sm:grid-cols-2">
                    @foreach ($areas as $area)
                        <li class="flex items-start gap-3"><svg class="mt-0.5 size-5 shrink-0 text-brand-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="{{ $area['icon'] }}" /></svg><div><p class="text-sm font-medium text-slate-900">{{ $area['label'] }}</p><p class="text-sm text-slate-600">{{ $area['hint'] }}</p></div></li>
                    @endforeach
                </ul>
            </x-knowledge-admin.section>
        @else
            <x-knowledge-admin.section title="O que você já pode fazer">
                <ul class="grid list-disc gap-1 pl-5 text-sm text-slate-700">
                    <li>Entender seus medicamentos com a bula humanizada e tirar dúvidas com base nas bulas cadastradas.</li>
                    <li>Registrar receitas, medicamentos em uso e alergias para receber alertas de interação.</li>
                    <li>Ver os seus dados de acesso e trocar a sua senha em Minha conta.</li>
                </ul>
            </x-knowledge-admin.section>
        @endif
    @endif

    <form method="POST" action="{{ route('first-access.password.update') }}" novalidate>
        @csrf
        @method('PUT')
        <x-knowledge-admin.section title="Sua senha" description="Use pelo menos 12 caracteres, com letras e números, diferente da senha provisória. As outras sessões abertas com a sua conta serão encerradas.">
            <x-knowledge-admin.input label="Nova senha" type="password" name="password" autocomplete="new-password" required autofocus />
            <x-knowledge-admin.input label="Confirme a nova senha" type="password" name="password_confirmation" autocomplete="new-password" required />
            <div><x-knowledge-admin.button type="submit" class="min-h-11 w-full sm:w-auto">Salvar senha e continuar</x-knowledge-admin.button></div>
        </x-knowledge-admin.section>
    </form>

    <div class="flex justify-end">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-knowledge-admin.button variant="secondary" type="submit">Sair</x-knowledge-admin.button>
        </form>
    </div>
@endsection
