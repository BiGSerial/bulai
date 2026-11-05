{{-- Item do menu "Mais" do page-header: link (href) ou ação (wire:click / x-on:click). `tone="danger"` para ações de risco. --}}
@props(['href' => null, 'tone' => 'default'])
@php
    $classes = 'flex w-full items-center gap-2 rounded-lg px-3 py-2.5 text-left text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 '.($tone === 'danger' ? 'text-rose-700 hover:bg-rose-50' : 'text-slate-700 hover:bg-slate-100');
    $opensNewTab = $attributes->get('target') === '_blank';
@endphp
@if ($href)
    <a role="menuitem" {{ $attributes->class($classes)->merge(['href' => $href]) }} @unless ($opensNewTab || $attributes->has('download')) wire:navigate @endunless>{{ $slot }}</a>
@else
    <button type="button" role="menuitem" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
