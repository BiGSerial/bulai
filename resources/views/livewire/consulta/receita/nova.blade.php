<x-consulta.shell title="Fotografar receita" :back="route('consulta.perfil', ['aba' => 'receitas'])">
    @if ($aviso)<p role="alert" class="rounded-xl bg-amber-50 px-4 py-3 font-medium text-amber-900">{{ $aviso }}</p>@endif

    @unless ($consentido)
        <section class="grid gap-4 rounded-2xl border-2 border-amber-300 bg-amber-50 p-5 text-amber-900" aria-labelledby="consent-receita">
            <h2 id="consent-receita" class="text-2xl font-semibold leading-tight">Antes, autorize guardar seus dados de saúde</h2>
            <p>A receita vira remédios em uso no seu perfil, e isso é dado de saúde. Aceite o consentimento e volte aqui.</p>
            <x-knowledge-admin.button :href="route('consulta.perfil')" class="min-h-16 w-full text-xl">Ir para Meus remédios</x-knowledge-admin.button>
        </section>
    @else
        <form class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" wire:submit="enviar" x-data="{ preview: null }" aria-labelledby="foto-titulo">
            <h2 id="foto-titulo" class="text-xl font-semibold leading-snug text-brand-900">Tire uma foto da receita inteira, com o texto nítido.</h2>

            <label for="foto-camera" class="flex min-h-20 cursor-pointer items-center justify-center gap-3 rounded-2xl bg-accent-600 px-4 py-4 text-xl font-semibold text-white shadow-sm hover:bg-accent-700 focus-within:ring-4 focus-within:ring-brand-600/40">
                <svg class="size-9" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" /></svg>
                Tirar foto da receita
                <input id="foto-camera" class="sr-only" type="file" accept="image/*" capture="environment" wire:model="foto" x-on:change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null">
            </label>
            <label for="foto-galeria" class="flex min-h-14 cursor-pointer items-center justify-center rounded-xl border-2 border-brand-700 bg-white px-4 py-2 text-lg font-semibold text-brand-900 hover:bg-brand-50 focus-within:ring-4 focus-within:ring-brand-600/40">
                Escolher da galeria
                <input id="foto-galeria" class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" wire:model="foto" x-on:change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null">
            </label>

            <div wire:loading wire:target="foto" role="status" class="text-slate-700">Carregando a foto…</div>

            @if ($foto)
                <figure class="grid gap-2">
                    <img class="max-h-80 w-full rounded-xl object-contain ring-1 ring-slate-200" :src="preview" src="{{ $foto->temporaryUrl() }}" alt="Pré-visualização da receita">
                    <figcaption class="text-base text-slate-700">Dá para ler o texto? Se sim, toque em “Ler esta receita”.</figcaption>
                </figure>
                <x-knowledge-admin.button type="submit" class="min-h-16 w-full text-xl" loadingTarget="enviar" loading="Enviando…">Ler esta receita</x-knowledge-admin.button>
            @endif
        </form>

        <p class="px-1 text-base text-slate-700">A foto é lida e apagada em seguida. Você confere cada remédio antes de salvar. O BULA-IA não muda a receita do seu médico. Formatos: JPG, PNG ou WebP, até {{ $maxMb }} MB.</p>
    @endunless
</x-consulta.shell>
