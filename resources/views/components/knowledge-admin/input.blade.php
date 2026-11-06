@props(['label', 'name' => null, 'hint' => null, 'required' => false, 'optional' => false, 'type' => 'text', 'compact' => false])
@php $errorKey = $name ?? $attributes->whereStartsWith('wire:model')->first(); @endphp
<x-knowledge-admin.field :label="$label" :name="$errorKey" :hint="$hint" :required="$required" :optional="$optional" :compact="$compact" :class="$attributes->get('wrapper-class')">
    <input {{ $attributes->except('wrapper-class')->class(['ui-control', 'h-9 py-1.5' => $compact])->merge(['type' => $type]) }} @if ($name) name="{{ $name }}" @endif @if ($errorKey) @error($errorKey) aria-invalid="true" @enderror @endif>
</x-knowledge-admin.field>
