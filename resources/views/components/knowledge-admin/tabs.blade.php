{{--
    Abas sublinhadas ligadas a uma propriedade Livewire (`model`, padrão `tab`).
    `alpine`: true quando a aba vive só no navegador (x-data com `tab`), sem propriedade Livewire.
    `tabs`: lista de ['key' => 'ativos', 'label' => 'Ativos', 'count' => 12|null]; `active`: chave atual.
--}}
@props(['tabs' => [], 'active' => null, 'model' => 'tab', 'alpine' => false])
<div {{ $attributes->class('flex gap-1 overflow-x-auto border-b-2 border-slate-300') }} role="tablist">
    @foreach ($tabs as $tab)
        @php($isActive = ($tab['key'] ?? null) === $active)
        <button type="button" role="tab" @if ($alpine) x-bind:aria-selected="{{ $model }} === '{{ $tab['key'] }}'" x-on:click="{{ $model }} = '{{ $tab['key'] }}'" x-bind:class="{{ $model }} === '{{ $tab['key'] }}' ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900'" @else aria-selected="{{ $isActive ? 'true' : 'false' }}" wire:click="$set('{{ $model }}', '{{ $tab['key'] }}')" @endif
                class="-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 {{ $alpine ? '' : ($isActive ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900') }}">
            {{ $tab['label'] }}
            @if (($tab['count'] ?? null) !== null)<span class="rounded-full px-1.5 text-xs tabular-nums {{ $isActive ? 'bg-brand-50 text-brand-800' : 'bg-slate-100 text-slate-600' }}">{{ $tab['count'] }}</span>@endif
        </button>
    @endforeach
</div>
