@php
$scrollTo = $scrollTo ?? 'body';
$scrollIntoView = $scrollTo !== false ? "(\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()" : '';
$pageName = $paginator->getPageName();
@endphp

<div>
    @if ($paginator->hasPages())
        <nav class="flex flex-wrap items-center justify-between gap-3" role="navigation" aria-label="Paginação">
            <p class="text-sm text-slate-600">Mostrando {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} de {{ $paginator->total() }}</p>
            <div class="flex items-center gap-2">
                <x-knowledge-admin.button variant="secondary" type="button" wire:click="previousPage('{{ $pageName }}')" x-on:click="{{ $scrollIntoView }}" :disabled="$paginator->onFirstPage()">Anterior</x-knowledge-admin.button>
                <span class="text-sm text-slate-600">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
                <x-knowledge-admin.button variant="secondary" type="button" wire:click="nextPage('{{ $pageName }}')" x-on:click="{{ $scrollIntoView }}" :disabled="! $paginator->hasMorePages()">Próxima</x-knowledge-admin.button>
            </div>
        </nav>
    @endif
</div>
