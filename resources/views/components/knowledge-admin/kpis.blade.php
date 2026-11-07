{{-- Grade de indicadores (`kpi`): duas colunas no celular, quatro a partir de `md`, seis em monitores largos (`2xl`). --}}
<div {{ $attributes->class('grid grid-cols-2 gap-3 md:grid-cols-4 2xl:grid-cols-6') }}>{{ $slot }}</div>
