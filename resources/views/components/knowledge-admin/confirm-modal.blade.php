@props(['title', 'message', 'confirmLabel', 'action', 'tone' => 'primary', 'loading' => null, 'loadingTarget' => null])
{{--
    Janela de confirmação no visual do modal. O botão que abre a janela vem no slot (sem wire:click);
    "action" é a mesma expressão que iria no wire:click, por exemplo toggleActive(5) ou clearFiles.
    Abre e fecha no navegador (Alpine); só a ação confirmada vai ao servidor, via $wire.
    Sem wire:key de propósito: no Livewire 4 ele entraria na chave dos componentes Livewire renderizados
    depois na página. Os ids (derivados da ação) já separam várias janelas na mesma tela.
--}}
@php
    $call = str_contains($action, '(') ? $action : $action.'()';
    $loadingTarget ??= $action;
    $dialogId = 'confirmacao-'.substr(md5($action), 0, 12);
@endphp
<div class="contents" x-data="{
    open: false,
    busy: false,
    origin: null,
    show(event) {
        this.origin = event.target.closest('button, a');
        this.open = true;
        this.$nextTick(() => requestAnimationFrame(() => this.$refs.cancel.focus()));
    },
    close() {
        if (this.busy || ! this.open) return;
        this.open = false;
        this.$nextTick(() => this.origin?.isConnected && this.origin.focus());
    },
    async run(action) {
        if (this.busy) return;
        this.busy = true;
        try {
            await action();
        } finally {
            this.busy = false;
            this.close();
        }
    },
}">
    <span class="contents" x-on:click="show($event)">{{ $slot }}</span>
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 sm:items-center" id="{{ $dialogId }}" role="dialog" aria-modal="true" aria-labelledby="{{ $dialogId }}-titulo" aria-describedby="{{ $dialogId }}-mensagem" x-show="open" x-cloak x-trap.noscroll.noreturn.noautofocus="open" x-on:keydown.escape.window="close()" x-on:click.self="close()">
        <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white shadow-xl">
            <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                <h2 class="text-base font-semibold text-slate-900" id="{{ $dialogId }}-titulo">{{ $title }}</h2>
                <x-knowledge-admin.button variant="quiet" x-on:click="close()" aria-label="Fechar" :loading-target="$loadingTarget">✕</x-knowledge-admin.button>
            </div>
            <p class="px-5 py-5 text-sm text-slate-700" id="{{ $dialogId }}-mensagem">{{ $message }}</p>
            <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end">
                <x-knowledge-admin.button variant="secondary" x-ref="cancel" x-on:click="close()" :loading-target="$loadingTarget">Cancelar</x-knowledge-admin.button>
                <x-knowledge-admin.button :variant="$tone === 'danger' ? 'danger' : 'primary'" x-on:click="run(() => $wire.{{ $call }})" :loading-target="$loadingTarget" :loading="$loading">{{ $confirmLabel }}</x-knowledge-admin.button>
            </div>
        </div>
    </div>
</div>
