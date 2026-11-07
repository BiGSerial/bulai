{{-- Lembrete de dose: horário e remédio em destaque; um único botão leva a "Remédios de hoje". --}}
<x-mail::message>
# Olá, {{ $name }}!

Está na hora do seu remédio.

<x-mail::panel>
**Às {{ $time }}**

{{ $medicine }}@if ($dosage !== ''), {{ $dosage }}@endif
</x-mail::panel>

Depois de tomar, marque “Tomei” em Remédios de hoje. Assim o {{ $app }} acompanha a sua rotina.

<x-mail::button :url="$todayUrl">
Ver remédios de hoje
</x-mail::button>

Para parar de receber lembretes por e-mail, desative “Também por e-mail” em Avisos, no aplicativo.

Equipe {{ $app }}
</x-mail::message>
