@php
    // Etapas da receita por foto: 1 Foto → 2 Conferir → 3 Pronto (esta tela é a etapa 1).
    $etapas = [[1, 'Foto'], [2, 'Conferir'], [3, 'Pronto']];
@endphp
<x-consulta.shell title="Fotografar receita" :back="route('consulta.perfil', ['aba' => 'receitas'])">
    <ol class="flex items-center justify-between gap-1 px-1" aria-label="Etapas">
        @foreach ($etapas as [$n, $rotulo])
            <li class="flex min-w-0 items-center gap-2 {{ $loop->last ? '' : 'flex-1' }}" @if ($n === 1) aria-current="step" @endif>
                <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-full text-base font-bold {{ $n === 1 ? 'bg-accent-600 text-white' : 'bg-slate-200 text-slate-600' }}">{{ $n }}</span>
                <span class="truncate text-base font-semibold {{ $n === 1 ? 'text-brand-900' : 'text-slate-500' }}">{{ $rotulo }}</span>
                @unless ($loop->last)<span class="mx-1 h-0.5 min-w-3 flex-1 rounded bg-slate-200" aria-hidden="true"></span>@endunless
            </li>
        @endforeach
    </ol>

    @if ($aviso)<p role="alert" class="rounded-2xl bg-amber-50 px-4 py-3 font-medium text-amber-900 ring-1 ring-amber-300">{{ $aviso }}</p>@endif

    @unless ($consentido)
        <x-consulta.cartao tone="warning" aria-labelledby="consent-receita">
            <h2 id="consent-receita" class="text-2xl font-semibold leading-tight">Antes, autorize guardar seus dados de saúde</h2>
            <p>A receita vira remédios em uso no seu perfil, e isso é dado de saúde. Aceite o consentimento e volte aqui.</p>
            <x-consulta.botao :href="route('consulta.perfil')">Ir para Meus remédios</x-consulta.botao>
        </x-consulta.cartao>
    @else
        <form class="grid min-w-0 gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" wire:submit="enviar" x-data="{ preview: null }" aria-labelledby="foto-titulo">
            <div class="flex items-start gap-3">
                <x-consulta.icone name="camera" tone="success" size="lg" />
                <h2 id="foto-titulo" class="min-w-0 text-xl font-semibold leading-snug text-brand-900">Tire uma foto da receita inteira, com o texto nítido.</h2>
            </div>

            <label for="foto-camera" class="flex min-h-20 cursor-pointer items-center justify-center gap-3 rounded-2xl bg-accent-600 px-4 py-4 text-center text-xl font-semibold leading-snug text-white shadow-sm transition hover:bg-accent-700 active:scale-[0.98] focus-within:ring-4 focus-within:ring-brand-600/40">
                <x-consulta.icone name="camera" plain class="size-9 shrink-0" />
                Tirar foto da receita
                <input id="foto-camera" class="sr-only" type="file" accept="image/*" capture="environment" wire:model="foto" x-on:change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null">
            </label>
            <label for="foto-galeria" class="flex min-h-14 cursor-pointer items-center justify-center rounded-2xl border-2 border-brand-700 bg-white px-4 py-2 text-center text-lg font-semibold text-brand-900 hover:bg-brand-50 focus-within:ring-4 focus-within:ring-brand-600/40">
                Escolher da galeria
                <input id="foto-galeria" class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" wire:model="foto" x-on:change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null">
            </label>

            <div wire:loading wire:target="foto" role="status" class="flex items-center gap-2 text-slate-700"><svg class="size-5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>Carregando a foto…</div>

            @if ($foto)
                <figure class="grid gap-2">
                    <img class="max-h-80 w-full rounded-2xl object-contain ring-1 ring-slate-200" :src="preview" src="{{ $foto->temporaryUrl() }}" alt="Pré-visualização da receita">
                    <figcaption class="text-base text-slate-700">Dá para ler o texto? Se sim, toque em “Ler esta receita”.</figcaption>
                </figure>
                <x-consulta.botao type="submit" loadingTarget="enviar" loading="Enviando…">Ler esta receita</x-consulta.botao>
            @endif
        </form>

        <ul class="grid gap-2 px-1 text-base text-slate-700">
            <li class="flex gap-2"><x-consulta.icone name="escudo" plain class="mt-0.5 size-5 shrink-0 text-brand-700" /><span>A foto é lida e apagada em seguida. Você confere cada remédio antes de salvar.</span></li>
            <li class="flex gap-2"><x-consulta.icone name="info" plain class="mt-0.5 size-5 shrink-0 text-brand-700" /><span>O BULA-IA não muda a receita do seu médico. Formatos: JPG, PNG ou WebP, até {{ $maxMb }} MB.</span></li>
        </ul>
    @endunless
</x-consulta.shell>
