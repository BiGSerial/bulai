<x-knowledge-admin.shell title="Origens" subtitle="Instituições e coleções de onde os documentos vêm (ex.: ANVISA).">
    <x-slot:actions><x-knowledge-admin.button :href="route('admin.knowledge.sources.create')">Nova origem</x-knowledge-admin.button></x-slot:actions>

    <x-knowledge-admin.toolbar :count="number_format($sources->total(), 0, ',', '.').' '.($sources->total() === 1 ? 'origem' : 'origens')">
        <x-knowledge-admin.search compact wrapper-class="w-full sm:w-80" label="Buscar" wire:model.live.debounce.300ms="search" placeholder="Nome, coleção ou país" />
        <x-knowledge-admin.select compact label="Situação" wire:model.live="status" wrapper-class="w-full sm:w-40"><option value="all">Todas</option><option value="active">Ativas</option><option value="inactive">Inativas</option></x-knowledge-admin.select>
        @if (trim($search) !== '' || $status !== 'all')<x-knowledge-admin.button variant="quiet" wire:click="clearFilters">Limpar</x-knowledge-admin.button>@endif
    </x-knowledge-admin.toolbar>

    <div wire:loading.class="opacity-60" wire:target="search,status,clearFilters,sortBy">
        @if ($sources->isEmpty())
            <x-knowledge-admin.empty-state>
                <p class="font-medium text-slate-800">Nenhuma origem encontrada.</p>
                <x-knowledge-admin.button class="mt-3" :href="route('admin.knowledge.sources.create')">Nova origem</x-knowledge-admin.button>
            </x-knowledge-admin.empty-state>
        @else
            <x-knowledge-admin.data-table :sort="$sort" :direction="$direction" :columns="[
                ['label' => 'Origem', 'sort' => 'name'],
                ['label' => 'Tipo'],
                ['label' => 'País', 'sort' => 'country'],
                ['label' => 'Documentos', 'sort' => 'documents', 'align' => 'right'],
                ['label' => 'Importações', 'sort' => 'batches', 'align' => 'right'],
                ['label' => 'Situação'],
                ['label' => '', 'class' => 'w-px'],
            ]">
                @foreach ($sources as $source)
                    <tr wire:key="source-{{ $source->id }}" class="hover:bg-brand-50/40">
                        <td class="max-w-sm px-3 py-2"><a class="block truncate font-semibold text-slate-900 hover:text-brand-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('admin.knowledge.sources.show', $source) }}" wire:navigate>{{ $source->displayName() }}</a></td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ \App\Models\Knowledge\KnowledgeSource::typeOptions()[$source->type] ?? 'Legada' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ $source->country ?: '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">{{ $source->documents_count }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums text-slate-700">{{ $source->batches_count }}</td>
                        <td class="whitespace-nowrap px-3 py-2"><x-knowledge-admin.badge :tone="$source->active ? 'success' : 'neutral'">{{ $source->active ? 'Ativa' : 'Inativa' }}</x-knowledge-admin.badge></td>
                        <td class="px-2 py-1">
                            <x-knowledge-admin.row-actions>
                                <x-knowledge-admin.button variant="quiet" size="sm" wire:click="toggleActive({{ $source->id }})" loading-target="toggleActive({{ $source->id }})">{{ $source->active ? 'Desativar' : 'Ativar' }}</x-knowledge-admin.button>
                                <x-knowledge-admin.button variant="quiet" size="sm" :href="route('admin.knowledge.sources.show', $source)">Abrir</x-knowledge-admin.button>
                            </x-knowledge-admin.row-actions>
                        </td>
                    </tr>
                @endforeach
            </x-knowledge-admin.data-table>
        @endif
    </div>
    {{ $sources->links() }}
</x-knowledge-admin.shell>
