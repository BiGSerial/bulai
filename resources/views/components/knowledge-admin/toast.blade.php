{{--
    Aviso breve depois de uma ação. Ouve o evento Livewire `notify` ({ message, tone }) disparado por
    `$this->dispatch('notify', message: '…', tone: 'success|info|warning|danger')` e some sozinho em 4 s.
    Incluído uma vez pelo shell; as telas só disparam o evento.
--}}
<div x-data="{
        items: [],
        push(detail) {
            const id = Date.now() + Math.random();
            this.items.push({ id, message: detail?.message ?? '', tone: detail?.tone ?? 'success' });
            setTimeout(() => this.remove(id), 4000);
        },
        remove(id) { this.items = this.items.filter(item => item.id !== id) },
    }"
    x-on:notify.window="push($event.detail)"
    class="pointer-events-none fixed inset-x-0 bottom-4 z-50 flex flex-col items-center gap-2 px-4 sm:items-end sm:px-6"
    role="status" aria-live="polite">
    <template x-for="item in items" :key="item.id">
        <div class="pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-xl border bg-white px-4 py-3 text-sm shadow-xl"
             x-bind:class="{
                'border-emerald-200 text-emerald-900': item.tone === 'success',
                'border-amber-200 text-amber-900': item.tone === 'warning',
                'border-rose-200 text-rose-900': item.tone === 'danger',
                'border-sky-200 text-sky-900': item.tone === 'info',
             }"
             x-transition.opacity.duration.200ms>
            <p class="min-w-0 flex-1 font-medium" x-text="item.message"></p>
            <button type="button" class="-mr-1 inline-flex size-7 shrink-0 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" x-on:click="remove(item.id)" aria-label="Fechar aviso">
                <svg class="size-4 stroke-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>
    </template>
</div>
