@props(['label', 'name' => null, 'hint' => null, 'required' => false, 'optional' => false])
@php $errorKey = $name ?? $attributes->whereStartsWith('wire:model')->first(); @endphp
<x-knowledge-admin.field :label="$label" :name="$errorKey" :hint="$hint" :required="$required" :optional="$optional" :class="$attributes->get('wrapper-class')">
    <textarea {{ $attributes->except('wrapper-class')->class('ui-control min-h-24')->merge(['rows' => 3]) }} @if ($name) name="{{ $name }}" @endif @if ($errorKey) @error($errorKey) aria-invalid="true" @enderror @endif></textarea>
</x-knowledge-admin.field>
