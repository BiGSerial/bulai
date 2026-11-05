@extends('layouts.auth')
@section('title', 'Redefinir senha')
@section('heading', 'Defina a nova senha')
@section('lead', 'Use pelo menos 12 caracteres, com letras e números. Ao salvar, todas as sessões abertas com a sua conta serão encerradas.')
@section('content')
    <form method="POST" action="{{ route('password.update') }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-knowledge-admin.card class="grid gap-5 sm:p-7">
            @error('email')<x-knowledge-admin.alert tone="danger" role="alert">{{ $message }}</x-knowledge-admin.alert>@enderror
            @error('token')<x-knowledge-admin.alert tone="danger" role="alert">{{ $message }}</x-knowledge-admin.alert>@enderror
            <x-knowledge-admin.input label="E-mail" type="email" name="email" :value="old('email', $email)" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" required />
            <x-knowledge-admin.input label="Nova senha" type="password" name="password" autocomplete="new-password" required autofocus class="min-h-14 text-lg" />
            <x-knowledge-admin.input label="Confirme a nova senha" type="password" name="password_confirmation" autocomplete="new-password" required class="min-h-14 text-lg" />
            <x-knowledge-admin.button type="submit" class="min-h-16 w-full text-xl">Salvar nova senha</x-knowledge-admin.button>
            <a class="inline-flex min-h-12 items-center justify-center font-semibold text-brand-800 underline-offset-2 hover:underline" href="{{ route('login') }}">Voltar para a tela de entrada</a>
        </x-knowledge-admin.card>
    </form>
@endsection
