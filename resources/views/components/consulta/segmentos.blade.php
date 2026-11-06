{{--
    Controle segmentado (abas em "pílulas"). itens: ['chave' => 'Rótulo'] ou ['chave' => ['Rótulo', contagem]].
    Modo Alpine: `modelo="aba"` (variável do x-data pai) e `ativo` ignorado. Modo servidor: `acao="mostrar"` + `ativo="chave"`.
--}}
@props(['itens', 'modelo' => null, 'acao' => null, 'ativo' => null, 'label' => 'Seções', 'prefixo' => 'seg'])
<div role="tablist" aria-label="{{ $label }}" {{ $attributes->class('grid gap-1 rounded-2xl bg-slate-200/70 p-1 '.(count($itens) === 3 ? 'grid-cols-3' : 'grid-cols-2')) }}>
    @foreach ($itens as $chave => $item)
        @php [$rotulo, $contagem] = is_array($item) ? $item : [$item, null]; @endphp
        <button type="button" role="tab" id="{{ $prefixo }}-{{ $chave }}" aria-controls="painel-{{ $chave }}"
            @if ($modelo) x-bind:aria-selected="{{ $modelo }} === '{{ $chave }}'" x-bind:tabindex="{{ $modelo }} === '{{ $chave }}' ? 0 : -1" x-on:click="{{ $modelo }} = '{{ $chave }}'" x-bind:class="{{ $modelo }} === '{{ $chave }}' ? 'bg-white text-brand-900 shadow-sm' : 'text-slate-700 hover:text-slate-900'"
            @else aria-selected="{{ $ativo === $chave ? 'true' : 'false' }}" wire:click="{{ $acao }}('{{ $chave }}')" @endif
            class="flex min-h-14 min-w-0 flex-col items-center justify-center rounded-xl px-1 text-lg font-semibold leading-tight transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 {{ ! $modelo && $ativo === $chave ? 'bg-white text-brand-900 shadow-sm' : (! $modelo ? 'text-slate-700 hover:text-slate-900' : '') }}">
            <span class="truncate max-w-full">{{ $rotulo }}</span>
            @if ($contagem !== null)<span class="text-base font-medium">{{ $contagem }}</span>@endif
        </button>
    @endforeach
</div>
