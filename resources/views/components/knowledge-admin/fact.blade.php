@props(['label'])
<div {{ $attributes->class('min-w-0') }}><dt class="text-sm text-slate-500">{{ $label }}</dt><dd class="mt-0.5 break-words text-sm font-medium text-slate-900">{{ $slot }}</dd></div>
