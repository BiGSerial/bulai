@props(['password', 'title' => 'Senha provisória', 'close' => 'closeModal'])
<x-knowledge-admin.modal :title="$title" description="Entregue a senha provisória à pessoa por um canal seguro. No primeiro acesso, ela precisará definir uma senha própria." :close="$close">
    <div class="grid gap-4 px-5 py-5" x-data="{ copied: false }">
        <x-knowledge-admin.alert tone="warning">Copie a senha agora: ela não será mostrada novamente. Se ela se perder, gere uma nova senha provisória.</x-knowledge-admin.alert>
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
            <span class="break-all font-mono text-base font-semibold text-slate-900" data-temporary-password>{{ $password }}</span>
            <x-knowledge-admin.button variant="secondary" x-on:click="navigator.clipboard.writeText(@js($password)); copied = true; setTimeout(() => copied = false, 1500)"><span x-show="! copied">Copiar</span><span x-show="copied" x-cloak>Copiado</span></x-knowledge-admin.button>
        </div>
    </div>
    <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4">
        <x-knowledge-admin.button wire:click="{{ $close }}">Concluir</x-knowledge-admin.button>
    </div>
</x-knowledge-admin.modal>
