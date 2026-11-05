{{-- Aviso breve após uma ação (Livewire: $this->dispatch('notify', message: '…', tone: 'success|warning')). Some sozinho em 4 s. --}}
<div x-data="{ open: false, message: '', tone: 'success', timer: null }"
     x-on:notify.window="message = $event.detail.message ?? ''; tone = $event.detail.tone ?? 'success'; open = message !== ''; clearTimeout(timer); timer = setTimeout(() => open = false, 4000)"
     x-cloak x-show="open" x-transition.opacity role="status" aria-live="polite"
     class="pointer-events-none fixed inset-x-0 top-14 z-40 flex justify-center px-4 sm:top-16">
    <p class="pointer-events-auto max-w-md rounded-xl px-4 py-2.5 text-sm font-medium shadow-lg ring-1"
       x-bind:class="tone === 'warning' ? 'bg-amber-50 text-amber-900 ring-amber-300' : 'bg-brand-800 text-white ring-brand-900'" x-text="message"></p>
</div>
