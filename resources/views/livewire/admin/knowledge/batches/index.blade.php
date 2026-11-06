<x-knowledge-admin.shell title="Importações" subtitle="Cada envio de PDFs e o que aconteceu com os arquivos.">
    <x-slot:actions><x-knowledge-admin.button :href="route('admin.knowledge.upload')">Adicionar documentos</x-knowledge-admin.button></x-slot:actions>

    <x-knowledge-admin.toolbar :count="number_format($batches->total(), 0, ',', '.').' '.($batches->total() === 1 ? 'importação' : 'importações')">
        <x-knowledge-admin.search compact wrapper-class="w-full sm:w-80" wire:model.live.debounce.350ms="search" placeholder="Nome, descrição ou origem" />
        <x-knowledge-admin.select compact label="Situação" wire:model.live="situation" wrapper-class="w-full sm:w-44">
            <option value="all">Todas</option>
            <option value="running">Em processamento</option>
            <option value="pending">Com pendências</option>
            <option value="errors">Com erros</option>
        </x-knowledge-admin.select>
        @if ($search !== '' || $situation !== 'all')<x-knowledge-admin.button variant="quiet" wire:click="clearFilters">Limpar</x-knowledge-admin.button>@endif
    </x-knowledge-admin.toolbar>

    <div wire:loading.class="opacity-60" wire:target="search,situation,clearFilters,sortBy">
        @if ($batches->isEmpty())
            <x-knowledge-admin.empty-state>
                <p class="font-medium text-slate-800">{{ $search !== '' || $situation !== 'all' ? 'Nenhuma importação com estes filtros.' : 'Nenhuma importação ainda.' }}</p>
                @if ($search !== '' || $situation !== 'all')<x-knowledge-admin.button class="mt-3" variant="secondary" wire:click="clearFilters">Limpar filtros</x-knowledge-admin.button>@else<x-knowledge-admin.button class="mt-3" :href="route('admin.knowledge.upload')">Adicionar documentos</x-knowledge-admin.button>@endif
            </x-knowledge-admin.empty-state>
        @else
            <x-knowledge-admin.data-table :sort="$sort" :direction="$direction" :columns="[
                ['label' => 'Importação', 'sort' => 'name'],
                ['label' => 'Origem'],
                ['label' => 'Arquivos', 'sort' => 'files', 'align' => 'right'],
                ['label' => 'Incorporados', 'align' => 'right'],
                ['label' => 'Pendentes', 'sort' => 'pending', 'align' => 'right'],
                ['label' => 'Erros', 'sort' => 'errors', 'align' => 'right'],
                ['label' => 'Situação'],
                ['label' => 'Enviada em', 'sort' => 'created'],
                ['label' => '', 'class' => 'w-px'],
            ]">
                @foreach ($batches as $batch)
                    <tr wire:key="batch-{{ $batch->id }}" class="hover:bg-brand-50/40">
                        <td class="max-w-sm px-3 py-2"><a class="block truncate font-semibold text-slate-900 hover:text-brand-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('admin.knowledge.batches.show', $batch) }}" wire:navigate>{{ $batch->name ?: 'Importação #'.$batch->id }}</a></td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ $batch->source?->name ?? 'Sem origem' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">{{ $batch->total_items }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">{{ $batch->associated_items + $batch->duplicate_items }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums {{ $batch->pending_items > 0 ? 'font-semibold text-amber-800' : 'text-slate-500' }}">{{ $batch->pending_items }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums {{ $batch->error_items > 0 ? 'font-semibold text-rose-700' : 'text-slate-500' }}">{{ $batch->error_items }}</td>
                        <td class="whitespace-nowrap px-3 py-2">
                            @if ($batch->pending_items > 0 && $batch->status === 'COMPLETED')
                                <x-knowledge-admin.badge tone="warning">Aguardando revisão</x-knowledge-admin.badge>
                            @else
                                <x-knowledge-admin.status-badge :status="$batch->status" />
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ $batch->created_at->format('d/m/Y H:i') }}</td>
                        <td class="px-2 py-1"><x-knowledge-admin.row-actions><x-knowledge-admin.button variant="quiet" size="sm" :href="route('admin.knowledge.batches.show', $batch)">Abrir</x-knowledge-admin.button></x-knowledge-admin.row-actions></td>
                    </tr>
                @endforeach
            </x-knowledge-admin.data-table>
        @endif
    </div>
    {{ $batches->links() }}
</x-knowledge-admin.shell>
