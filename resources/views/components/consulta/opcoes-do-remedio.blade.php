{{--
    Opções de um remédio em Meus remédios (folha de ações) e o formulário "Dose e horários" quando aberto. O pai declara
    `x-data="{ folha: false, confirmarRemover: false }"`. Em um tratamento `encerrado`, mudar a data final o devolve à lista.
--}}
@props(['item', 'editando' => false, 'posologia' => [], 'receitas' => null, 'encerrado' => false])
<x-consulta.folha-acoes modelo="folha" :titulo="$item->displayName()">
    <div class="grid gap-2" x-show="!confirmarRemover">
        @if ($item->isMatched())<x-consulta.botao variant="soft" size="md" :href="route('consulta.bula', $item->medicine_id)" aria-label="Bula humanizada de {{ $item->displayName() }}"><x-consulta.icone name="bula" plain class="size-6" />Ver a bula simples</x-consulta.botao>@endif
        <x-consulta.botao variant="soft" size="md" wire:click="editarPosologia({{ $item->id }})" x-on:click="folha = false" aria-label="Editar posologia de {{ $item->displayName() }}"><x-consulta.icone name="editar" plain class="size-6" />{{ $encerrado ? 'Voltar a usar (mudar as datas)' : 'Informar dose e horários' }}</x-consulta.botao>
        <button type="button" class="inline-flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl border border-rose-300 bg-white px-4 text-lg font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-rose-600/30" x-on:click="confirmarRemover = true"><x-consulta.icone name="apagar" plain class="size-6" />Tirar da minha lista</button>
    </div>
    <div class="grid gap-2" x-show="confirmarRemover" x-cloak>
        <p class="font-semibold text-rose-900">Tirar {{ $item->displayName() }} da sua lista? As marcações deste remédio também somem.</p>
        <x-consulta.botao variant="danger" size="md" wire:click="removerMedicamento({{ $item->id }})" wire:loading.attr="disabled" aria-label="Remover {{ $item->displayName() }}">Sim, tirar</x-consulta.botao>
        <x-consulta.botao variant="quiet" size="sm" x-on:click="confirmarRemover = false">Não</x-consulta.botao>
    </div>
</x-consulta.folha-acoes>

@if ($editando)
    <x-consulta.form-posologia :item="$item" :posologia="$posologia" :receitas="$receitas" />
@endif
