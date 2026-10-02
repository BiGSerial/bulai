@props(['title', 'description' => null, 'close' => 'closeModal'])
<div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 sm:items-center" role="dialog" aria-modal="true" aria-label="{{ $title }}" wire:keydown.escape="{{ $close }}" wire:click="{{ $close }}">
    <div class="w-full max-w-2xl rounded-xl border border-slate-200 bg-white shadow-xl" wire:click.stop>
        <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
            <div><h2 class="text-base font-semibold text-slate-900">{{ $title }}</h2>@if ($description)<p class="mt-1 text-sm text-slate-600">{{ $description }}</p>@endif</div>
            <x-knowledge-admin.button variant="quiet" wire:click="{{ $close }}" aria-label="Fechar">✕</x-knowledge-admin.button>
        </div>
        {{ $slot }}
    </div>
</div>
