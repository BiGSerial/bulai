@extends('layouts.auth')
@section('title', 'Entrar')
@section('heading', 'Entrar')
@section('lead', 'Acesse o aplicativo do paciente ou a Área administrativa com a sua conta.')
@section('compact', '1')
@section('content')
    <form method="POST" action="{{ route('login.store') }}" novalidate>
        @csrf
        <x-knowledge-admin.card class="grid gap-3 sm:gap-5 sm:p-7">
            @error('email')<x-knowledge-admin.alert tone="danger" id="login-erro" role="alert">{{ $message }}</x-knowledge-admin.alert>@enderror
            <x-knowledge-admin.input label="E-mail" type="email" name="email" :value="old('email')" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" required autofocus aria-describedby="login-erro" class="min-h-12 text-lg sm:min-h-14" />
            <x-knowledge-admin.input label="Senha" type="password" name="password" autocomplete="current-password" required class="min-h-12 text-lg sm:min-h-14" />
            <label class="flex min-h-12 items-center gap-3 text-slate-800"><input class="size-6 rounded border-slate-300 text-brand-700 focus:ring-brand-600" name="remember" type="checkbox" value="1" @checked(old('remember'))>Continuar conectado neste aparelho</label>
            <x-knowledge-admin.button variant="accent" type="submit" class="min-h-14 w-full text-xl sm:min-h-16">Entrar</x-knowledge-admin.button>
            <a class="inline-flex min-h-11 items-center justify-center font-semibold text-brand-800 underline-offset-2 hover:underline" href="{{ route('password.request') }}">Esqueci minha senha</a>
        </x-knowledge-admin.card>
    </form>
    {{-- Cadastro em resumo: o aviso completo do projeto acadêmico (TCC) fica na tela de cadastro. --}}
    <x-knowledge-admin.card class="grid gap-2 text-center sm:gap-3 sm:p-7">
        <p class="text-base leading-snug text-slate-700"><span class="font-semibold text-brand-900">Ainda não tem conta?</span> Faça o seu cadastro; a equipe aprova cada pedido e avisa você por e-mail.</p>
        <x-knowledge-admin.button variant="secondary" :href="route('register')" class="min-h-12 w-full text-lg sm:min-h-14">Criar minha conta</x-knowledge-admin.button>
    </x-knowledge-admin.card>
@endsection
