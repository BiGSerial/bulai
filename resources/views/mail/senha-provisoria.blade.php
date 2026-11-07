{{-- Senha provisória: a senha vai em painel de destaque (creme, barra azul-marinho) e o passo seguinte fica explícito. --}}
<x-mail::message>
# Olá, {{ $name }}!

{{ $isNewAccount ? 'Um administrador criou o seu acesso ao '.$app.'.' : 'Um administrador gerou uma nova senha provisória para o seu acesso ao '.$app.'.' }}

<x-mail::panel>
**Sua senha provisória**

`{{ $password }}`
</x-mail::panel>

<x-mail::button :url="$loginUrl">
Entrar no {{ $app }}
</x-mail::button>

**Como funciona**

1. Entre com o seu e-mail e a senha provisória acima.
2. No primeiro acesso você vai criar uma senha só sua.
3. Depois disso, a senha provisória deixa de valer.

Se você não esperava este e-mail, não use a senha e avise um administrador.

Equipe {{ $app }}
</x-mail::message>
