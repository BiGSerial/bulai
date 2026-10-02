@props(['label' => 'Buscar'])
<x-knowledge-admin.input type="search" :label="$label" {{ $attributes->merge(['wrapper-class' => 'w-full sm:max-w-md']) }} />
