<x-knowledge-admin.shell :title="$sourceId === null ? 'Nova origem' : 'Editar origem'">
    <form class="grid max-w-3xl gap-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6" wire:submit="save">
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="grid gap-1 text-sm font-semibold text-slate-700">Nome *<input class="rounded-lg border-slate-300" type="text" wire:model="name" placeholder="ANVISA">@error('name')<span class="font-normal text-red-700">{{ $message }}</span>@enderror</label>
            <label class="grid gap-1 text-sm font-semibold text-slate-700">Base / coleção *<input class="rounded-lg border-slate-300" type="text" wire:model="collection" placeholder="Bulário Eletrônico">@error('collection')<span class="font-normal text-red-700">{{ $message }}</span>@enderror</label>
            <label class="grid gap-1 text-sm font-semibold text-slate-700">Tipo *<select class="rounded-lg border-slate-300" wire:model="type">@foreach($typeOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>@error('type')<span class="font-normal text-red-700">{{ $message }}</span>@enderror</label>
            <label class="grid gap-1 text-sm font-semibold text-slate-700">País<input class="rounded-lg border-slate-300" type="text" wire:model="country" placeholder="Brasil">@error('country')<span class="font-normal text-red-700">{{ $message }}</span>@enderror</label>
        </div>
        <label class="grid gap-1 text-sm font-semibold text-slate-700">URL oficial<input class="rounded-lg border-slate-300" type="url" wire:model="url" placeholder="https://...">@error('url')<span class="font-normal text-red-700">{{ $message }}</span>@enderror</label>
        <label class="grid gap-1 text-sm font-semibold text-slate-700">Descrição<textarea class="min-h-28 rounded-lg border-slate-300" wire:model="description" placeholder="Contexto administrativo opcional"></textarea>@error('description')<span class="font-normal text-red-700">{{ $message }}</span>@enderror</label>
        <div class="flex flex-wrap gap-3"><button class="rounded-lg bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-60" type="submit" wire:loading.attr="disabled"><span wire:loading.remove wire:target="save">Salvar origem</span><span wire:loading wire:target="save">Salvando…</span></button><a class="rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-800" href="{{ route('admin.knowledge.sources.index') }}" wire:navigate>Cancelar</a></div>
    </form>
</x-knowledge-admin.shell>
