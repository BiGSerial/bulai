{{--
    Tabela densa para listas da administração (densidade profissional, princípios de UX §10).
    `columns`: lista de ['label' => 'Título', 'class' => 'w-1/2', 'sort' => 'title'|null, 'align' => 'left'|'right'].
    `sort`/`direction`: coluna e sentido atuais; `sortEvent`: método Livewire chamado com o campo (padrão `sortBy`).
    Slot padrão = linhas `<tr>`; slot `empty` = estado vazio em uma linha (já envolto em `<tr><td>`).
    Alinhamento: a célula segue o `align` do cabeçalho da coluna (`right` para números; coluna sem título = ações, à direita).
    Cabeçalho escuro (contraste) fixo abaixo do cabeçalho do shell em telas grandes; linhas h-12 (`dense` → h-10), zebra e destaque ao passar o mouse.
    O texto das células QUEBRA por padrão (antes era `nowrap` + `max-w-*`, o que fazia o texto vazar sobre a coluna vizinha); use
    `whitespace-nowrap` só em células curtas (datas, números) e `truncate` + `max-w-*` só em um bloco interno. O cabeçalho nunca quebra por letra.
    No celular a tabela rola na horizontal, a primeira coluna fica fixa à esquerda e uma sombra indica que há mais colunas.
--}}
@props(['columns' => [], 'sort' => null, 'direction' => 'asc', 'sortEvent' => 'sortBy', 'dense' => false])
@php
    $rowHeight = $dense ? '[&_tbody_tr]:h-10' : '[&_tbody_tr]:h-12';
    $cellPadding = $dense ? '[&_td]:px-3 [&_td]:py-1.5' : '[&_td]:px-3 [&_td]:py-2';
    // Cada célula herda o alinhamento do cabeçalho da própria coluna (números à direita, texto à esquerda); coluna sem título
    // (ações) vai à direita. Classes literais para o Tailwind enxergá-las; acima de 12 colunas valem as da esquerda.
    $rightAligned = [1 => '[&_td:nth-child(1)]:text-right', 2 => '[&_td:nth-child(2)]:text-right', 3 => '[&_td:nth-child(3)]:text-right', 4 => '[&_td:nth-child(4)]:text-right', 5 => '[&_td:nth-child(5)]:text-right', 6 => '[&_td:nth-child(6)]:text-right', 7 => '[&_td:nth-child(7)]:text-right', 8 => '[&_td:nth-child(8)]:text-right', 9 => '[&_td:nth-child(9)]:text-right', 10 => '[&_td:nth-child(10)]:text-right', 11 => '[&_td:nth-child(11)]:text-right', 12 => '[&_td:nth-child(12)]:text-right'];
    $alignClasses = collect($columns)->values()->map(function ($column, int $index) use ($rightAligned): string {
        $column = is_array($column) ? $column : ['label' => (string) $column];
        $isRight = ($column['align'] ?? null) === 'right' || (($column['align'] ?? null) === null && trim((string) ($column['label'] ?? '')) === '');

        return $isRight ? ($rightAligned[$index + 1] ?? '') : '';
    })->filter()->implode(' ');
@endphp
<div {{ $attributes->class('overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-300 lg:overflow-clip') }}>
    <table class="w-full min-w-max border-separate border-spacing-0 text-sm text-slate-800 lg:min-w-full {{ $rowHeight }} {{ $cellPadding }} {{ $alignClasses }} [&_tbody_tr:nth-child(even)]:bg-slate-50 [&_tbody_tr:hover]:bg-brand-50 [&_td]:border-b [&_td]:border-slate-200 [&_td]:align-middle [&_td]:break-words [&_td:first-child]:font-medium [&_td:first-child]:text-slate-900 max-lg:[&_td:first-child]:sticky max-lg:[&_td:first-child]:left-0 max-lg:[&_td:first-child]:z-10 max-lg:[&_td:first-child]:bg-white max-lg:[&_tbody_tr:nth-child(even)_td:first-child]:bg-slate-50 max-lg:[&_td:first-child]:shadow-sm [&_tbody_tr:last-child_td]:border-b-0 [&_td.tabular]:tabular-nums">
        <thead class="lg:sticky lg:top-14 lg:z-10">
            <tr>
                @foreach ($columns as $column)
                    @php
                        $column = is_array($column) ? $column : ['label' => (string) $column];
                        $align = ($column['align'] ?? 'left') === 'right' ? 'text-right' : 'text-left';
                        $field = $column['sort'] ?? null;
                        $isSorted = $field !== null && $field === $sort;
                    @endphp
                    <th scope="col" class="whitespace-nowrap border-b border-brand-900 bg-brand-700 px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-white {{ $align }} {{ $column['class'] ?? '' }}" @if ($isSorted) aria-sort="{{ $direction === 'desc' ? 'descending' : 'ascending' }}" @endif>
                        @if ($field !== null)
                            <button type="button" class="inline-flex items-center gap-1 rounded uppercase hover:text-accent-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-400 {{ $isSorted ? 'text-accent-200' : '' }}" wire:click="{{ $sortEvent }}('{{ $field }}')">
                                {{ $column['label'] }}
                                <svg class="size-3 stroke-2 {{ $isSorted ? '' : 'opacity-40' }} {{ $isSorted && $direction === 'desc' ? 'rotate-180' : '' }}" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path d="m4.5 15.75 7.5-7.5 7.5 7.5" /></svg>
                            </button>
                        @else
                            {{ $column['label'] }}
                        @endif
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            {{ $slot }}
            @isset($empty)
                <tr class="!h-auto"><td colspan="{{ max(1, count($columns)) }}" class="!py-6 !text-center !font-normal !text-slate-700 !whitespace-normal">{{ $empty }}</td></tr>
            @endisset
        </tbody>
    </table>
</div>
