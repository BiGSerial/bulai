{{-- Grade de indicadores (`kpi`): duas colunas no celular, quatro a partir de `md`. --}}
<div {{ $attributes->class('grid grid-cols-2 gap-2 md:grid-cols-4') }}>{{ $slot }}</div>
