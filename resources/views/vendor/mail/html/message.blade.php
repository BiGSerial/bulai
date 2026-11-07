<x-mail::layout>
{{-- Cabeçalho da marca (símbolo, nome e lema) --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Corpo --}}
{!! $slot !!}

{{-- Texto de apoio --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Rodapé: quem enviou, o aviso do produto e o caminho de volta ao aplicativo --}}
<x-slot:footer>
<x-mail::footer>
**{{ config('app.name') }}** · Bulas inteligentes

{{ config('bulai.guidelines.disclaimer') }}

Você recebeu esta mensagem porque tem uma conta no {{ config('app.name') }}. [Abrir o aplicativo]({{ config('app.url') }})
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
