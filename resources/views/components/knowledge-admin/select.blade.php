@props(['label', 'name' => null, 'hint' => null, 'required' => false, 'optional' => false, 'compact' => false])
@php $errorKey = $name ?? $attributes->whereStartsWith('wire:model')->first(); @endphp
<x-knowledge-admin.field :label="$label" :name="$errorKey" :hint="$hint" :required="$required" :optional="$optional" :compact="$compact" :class="$attributes->get('wrapper-class')">
    <select {{ $attributes->except('wrapper-class')->class(['ui-control', 'h-9 py-1.5' => $compact]) }} @if ($name) name="{{ $name }}" @endif @if ($errorKey) @error($errorKey) aria-invalid="true" @enderror @endif>{{ $slot }}</select>
</x-knowledge-admin.field>
