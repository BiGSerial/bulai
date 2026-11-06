{{-- Campo de busca de listas. Em barras de filtro use `compact` (rótulo oculto, altura 36px). --}}
@props(['label' => 'Buscar', 'compact' => false])
<x-knowledge-admin.input type="search" :label="$label" :compact="$compact" {{ $attributes->merge(['wrapper-class' => $compact ? 'w-full sm:w-72' : 'w-full sm:max-w-md']) }} />
