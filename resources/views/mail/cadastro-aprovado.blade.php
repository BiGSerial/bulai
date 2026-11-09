{{-- Cadastro aprovado: a boa notícia em destaque e um único botão para entrar com a senha que a pessoa já escolheu. --}}
<x-mail::message>
# Olá, {{ $name }}!

Boa notícia: o seu cadastro no {{ $app }} foi aprovado. Você já pode entrar.

<x-mail::panel>
**Como entrar**

Use o e-mail {{ $email }} e a senha que você criou no cadastro.
</x-mail::panel>

<x-mail::button :url="$loginUrl">
Entrar no {{ $app }}
</x-mail::button>

Esqueceu a senha? Na tela de entrada, toque em “Esqueci minha senha” e enviaremos um link para criar outra.

Lembrete: o {{ $app }} é um protótipo acadêmico (Trabalho de Conclusão de Curso). Ele ajuda a entender a bula, mas não substitui o seu médico ou farmacêutico.

Se você não fez este cadastro, ignore este e-mail.

Equipe {{ $app }}
</x-mail::message>
