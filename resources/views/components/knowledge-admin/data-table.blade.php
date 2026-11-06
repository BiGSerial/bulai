{{--
    Tabela densa para listas da administração (densidade profissional, princípios de UX §10).
    `columns`: lista de ['label' => 'Título', 'class' => 'w-1/2', 'sort' => 'title'|null, 'align' => 'left'|'right'].
    `sort`/`direction`: coluna e sentido atuais; `sortEvent`: método Livewire chamado com o campo (padrão `sortBy`).
    Slot padrão = linhas `<tr>`; slot `empty` = estado vazio em uma linha (já envolto em `<tr><td>`).
    Cabeçalho fixo abaixo do cabeçalho do shell em telas grandes; linhas h-12 (`dense` → h-10), zebra sutil e destaque ao passar o mouse.
--}}
@props(['columns' => [], 'sort' => null, 'direction' => 'asc', 'sortEvent' => 'sortBy', 'dense' => false])
@php
    $rowHeight = $dense ? '[&_tbody_tr]:h-10' : '[&_tbody_tr]:h-12';
    $cellPadding = $dense ? '[&_td]:px-3 [&_td]:py-1.5' : '[&_td]:px-3 [&_td]:py-2';
@endphp
<div {{ $attributes->class('overflow-x-auto rounded-lg bg-white ring-1 ring-slate-200 lg:overflow-visible') }}>
    <table class="w-full min-w-max border-separate border-spacing-0 text-sm text-slate-700 lg:min-w-full {{ $rowHeight }} {{ $cellPadding }} [&_tbody_tr:nth-child(even)]:bg-surface-50 [&_tbody_tr:hover]:bg-brand-50/40 [&_td]:border-b [&_td]:border-slate-100 [&_td]:align-middle [&_td]:whitespace-nowrap [&_td:first-child]:font-medium [&_td:first-child]:text-slate-900 [&_td:last-child]:text-right [&_tbody_tr:last-child_td]:border-b-0 [&_td.tabular]:tabular-nums">
        <thead class="lg:sticky lg:top-14 lg:z-10">
            <tr>
                @foreach ($columns as $column)
                    @php
                        $column = is_array($column) ? $column : ['label' => (string) $column];
                        $align = ($column['align'] ?? 'left') === 'right' ? 'text-right' : 'text-left';
                        $field = $column['sort'] ?? null;
                        $isSorted = $field !== null && $field === $sort;
                    @endphp
                    <th scope="col" class="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 {{ $align }} {{ $column['class'] ?? '' }}" @if ($isSorted) aria-sort="{{ $direction === 'desc' ? 'descending' : 'ascending' }}" @endif>
                        @if ($field !== null)
                            <button type="button" class="inline-flex items-center gap-1 rounded uppercase hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 {{ $isSorted ? 'text-slate-900' : '' }}" wire:click="{{ $sortEvent }}('{{ $field }}')">
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
                <tr class="!h-auto"><td colspan="{{ max(1, count($columns)) }}" class="!py-6 !text-center !font-normal !text-slate-600 !whitespace-normal">{{ $empty }}</td></tr>
            @endisset
        </tbody>
    </table>
</div>
