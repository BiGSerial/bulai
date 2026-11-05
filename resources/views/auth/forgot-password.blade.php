@extends('layouts.auth')
@section('title', 'Esqueci minha senha')
@section('heading', 'Esqueci minha senha')
@section('lead', 'Informe o e-mail da sua conta. Se ele estiver cadastrado, enviaremos um link para definir uma nova senha.')
@section('content')
    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf
        <x-knowledge-admin.card class="grid gap-5 sm:p-7">
            @error('email')<x-knowledge-admin.alert tone="danger" id="esqueci-erro" role="alert">{{ $message }}</x-knowledge-admin.alert>@enderror
            <x-knowledge-admin.input label="E-mail" type="email" name="email" :value="old('email')" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" required autofocus aria-describedby="esqueci-erro" class="min-h-14 text-lg" />
            <x-knowledge-admin.button type="submit" class="min-h-16 w-full text-xl">Enviar link de redefinição</x-knowledge-admin.button>
            <a class="inline-flex min-h-12 items-center justify-center font-semibold text-brand-800 underline-offset-2 hover:underline" href="{{ route('login') }}">Voltar para a tela de entrada</a>
        </x-knowledge-admin.card>
    </form>
@endsection
