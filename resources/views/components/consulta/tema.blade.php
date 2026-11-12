{{-- Escolha do tema (claro, escuro ou igual ao celular), guardada neste aparelho por resources/js/tema.js. --}}
@props(['compacto' => false])
<div {{ $attributes->class('grid gap-1.5') }} x-data="{ tema: window.bulaiTema?.atual() ?? 'claro' }" x-on:bulai-tema.window="tema = $event.detail.tema" role="radiogroup" aria-label="Aparência">
    <p class="{{ $compacto ? 'px-2 text-sm font-semibold uppercase tracking-wide text-slate-500' : 'text-lg font-semibold text-slate-900' }}">Aparência</p>
    <div class="flex gap-1 rounded-xl bg-slate-200/70 p-1">
        @foreach (['claro' => ['Claro', 'sol'], 'escuro' => ['Escuro', 'lua'], 'sistema' => ['Do celular', 'celular']] as $valor => [$rotulo, $icone])
            <button type="button" role="radio" x-bind:aria-checked="tema === '{{ $valor }}'" x-on:click="window.bulaiTema?.definir('{{ $valor }}')"
                x-bind:class="tema === '{{ $valor }}' ? 'bg-white text-brand-900 shadow-sm ring-1 ring-brand-600/40' : 'text-slate-700 hover:text-slate-900'"
                class="flex {{ $compacto ? 'min-h-11' : 'min-h-12' }} min-w-0 flex-1 flex-col items-center justify-center gap-0.5 rounded-lg px-1 text-sm font-semibold leading-tight focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                <x-consulta.icone :name="$icone" plain class="size-5" /><span class="max-w-full truncate">{{ $rotulo }}</span>
            </button>
        @endforeach
    </div>
</div>
