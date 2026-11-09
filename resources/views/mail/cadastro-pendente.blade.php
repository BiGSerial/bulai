{{-- Aviso ao administrador: quem se cadastrou (só nome e e-mail) e um botão direto para a página de aprovação. --}}
<x-mail::message>
# Olá, {{ $adminName }}!

Uma pessoa se cadastrou no {{ $app }} pelo site e aguarda a sua aprovação para entrar.

<x-mail::panel>
**{{ $name }}**

{{ $email }}@if ($registeredAt) · cadastro em {{ $registeredAt }}@endif
</x-mail::panel>

<x-mail::button :url="$reviewUrl">
Revisar cadastro
</x-mail::button>

Ao aprovar, a pessoa recebe um e-mail avisando que já pode entrar. Se recusar, ela continua sem acesso.

Equipe {{ $app }}
</x-mail::message>
