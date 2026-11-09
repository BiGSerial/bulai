@extends('layouts.auth')
@section('title', 'Criar conta')
@section('width', 'lg')
@if (session('registered'))
    @section('heading', 'Cadastro recebido!')
    @section('lead', 'Obrigado, '.session('registered').'. Agora é só aguardar a aprovação.')
@else
    @section('heading', 'Criar minha conta')
    @section('lead', 'Preencha os dados abaixo para pedir o seu acesso. Leva menos de dois minutos.')
@endif
@section('content')
    {{-- Aviso acadêmico sempre visível, antes do formulário: protótipo de TCC, acesso limitado e sujeito a aprovação. --}}
    <section class="grid gap-3 rounded-xl border-2 border-amber-300 bg-amber-50 p-5 text-amber-950" aria-labelledby="aviso-academico-titulo" data-aviso-academico>
        <h2 id="aviso-academico-titulo" class="flex items-center gap-2 text-xl font-semibold">
            <x-landing.icon name="academic-cap" class="size-7 shrink-0 text-amber-700" />
            Projeto acadêmico: acesso limitado
        </h2>
        <p>O BULA-IA é um <strong>protótipo criado como Trabalho de Conclusão de Curso (TCC)</strong>. Por isso, o número de acessos é limitado e <strong>cada cadastro passa pela aprovação da equipe do projeto</strong> antes do primeiro uso.</p>
        <p>Você recebe um e-mail assim que o seu acesso for liberado.</p>
    </section>

    @if (session('registered'))
        <x-knowledge-admin.card class="grid gap-4 sm:p-7" role="status">
            <p>O seu pedido de acesso foi enviado para a equipe do projeto.</p>
            <ol class="grid list-decimal gap-2 pl-6">
                <li>A equipe confere o cadastro.</li>
                <li>Quando o acesso for aprovado, você recebe um e-mail.</li>
                <li>Depois disso, é só entrar com o seu e-mail e a senha que você criou.</li>
            </ol>
            <x-knowledge-admin.button variant="accent" :href="route('login')" class="min-h-16 w-full text-xl">Ir para a tela de entrada</x-knowledge-admin.button>
            <a class="inline-flex min-h-12 items-center justify-center font-semibold text-brand-800 underline-offset-2 hover:underline" href="{{ url('/') }}">Voltar para a página inicial</a>
        </x-knowledge-admin.card>
    @else
        <form method="POST" action="{{ route('register.store') }}" novalidate>
            @csrf
            <x-knowledge-admin.card class="grid gap-5 sm:p-7">
                @if ($errors->any())<x-knowledge-admin.alert tone="danger" id="cadastro-erro" role="alert">Confira os campos destacados abaixo.</x-knowledge-admin.alert>@endif
                <x-knowledge-admin.input label="Seu nome" name="name" :value="old('name')" autocomplete="name" maxlength="255" required autofocus class="min-h-14 text-lg" />
                <x-knowledge-admin.input label="E-mail" type="email" name="email" :value="old('email')" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" maxlength="255" required hint="É para este e-mail que avisaremos quando o acesso for liberado." class="min-h-14 text-lg" />
                <x-knowledge-admin.input label="Crie uma senha" type="password" name="password" autocomplete="new-password" required hint="Use pelo menos 12 caracteres, com letras e números." class="min-h-14 text-lg" />
                <x-knowledge-admin.input label="Repita a senha" type="password" name="password_confirmation" autocomplete="new-password" required class="min-h-14 text-lg" />

                <div class="grid gap-3 rounded-xl bg-surface-100 p-4 text-base text-slate-800">
                    <h2 class="font-semibold text-brand-900">Como cuidamos dos seus dados</h2>
                    <ul class="grid list-disc gap-1.5 pl-6">
                        <li>No cadastro pedimos só <strong>nome, e-mail e senha</strong>. Nenhum dado de saúde.</li>
                        <li>Esses dados servem apenas para você entrar no aplicativo e para avisarmos sobre o seu acesso.</li>
                        <li>Remédios, alergias e receitas só são guardados depois, se você autorizar dentro do aplicativo, e podem ser apagados por você.</li>
                        <li>A senha é guardada de forma protegida: nem a equipe consegue vê-la.</li>
                        <li>Por ser um projeto acadêmico, o serviço pode mudar ou ser encerrado ao fim do trabalho.</li>
                    </ul>
                    <label class="flex items-start gap-3 font-medium text-slate-900">
                        <input class="mt-1 size-6 shrink-0 rounded border-slate-300 text-brand-700 focus:ring-brand-600" name="terms" type="checkbox" value="1" required @checked(old('terms')) @error('terms') aria-invalid="true" @enderror>
                        <span>Li e concordo com este aviso. Entendo que o BULA-IA é um projeto acadêmico, que não substitui médico ou farmacêutico e que o meu acesso depende de aprovação.</span>
                    </label>
                    @error('terms')<p class="text-sm text-rose-700">{{ $message }}</p>@enderror
                </div>

                <x-knowledge-admin.button variant="accent" type="submit" class="min-h-16 w-full text-xl">Enviar meu cadastro</x-knowledge-admin.button>
                <a class="inline-flex min-h-12 items-center justify-center font-semibold text-brand-800 underline-offset-2 hover:underline" href="{{ route('login') }}">Já tenho conta: entrar</a>
            </x-knowledge-admin.card>
        </form>
    @endif
@endsection
