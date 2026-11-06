<x-knowledge-admin.shell :title="$source->displayName()" :back="route('admin.knowledge.sources.index')" back-label="Origens">
    <x-knowledge-admin.page-header :badge="$source->active ? 'Ativa' : 'Inativa'" :badge-tone="$source->active ? 'success' : 'neutral'" :meta="[$typeLabel, $source->country ?: null]">
        <x-slot:primary><x-knowledge-admin.button :href="route('admin.knowledge.sources.edit', $source)">Editar</x-knowledge-admin.button></x-slot:primary>
        <x-slot:more>
            <x-knowledge-admin.menu-item wire:click="toggleActive">{{ $source->active ? 'Desativar origem' : 'Ativar origem' }}</x-knowledge-admin.menu-item>
            <x-knowledge-admin.menu-item :href="route('admin.knowledge.upload')">Adicionar documentos desta origem</x-knowledge-admin.menu-item>
            @if ($source->url)<x-knowledge-admin.menu-item :href="$source->url" target="_blank" rel="noopener noreferrer">Abrir endereço oficial</x-knowledge-admin.menu-item>@endif
        </x-slot:more>
    </x-knowledge-admin.page-header>

    @if (session('sourceFeedback'))<x-knowledge-admin.alert tone="success">{{ session('sourceFeedback') }}</x-knowledge-admin.alert>@endif

    <x-knowledge-admin.card class="grid gap-3">
        <x-knowledge-admin.facts class="sm:grid-cols-3 lg:grid-cols-4">
            <x-knowledge-admin.fact label="Documentos">{{ $source->documents_count }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Importações">{{ $source->batches_count }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Tipo">{{ $typeLabel }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Endereço oficial"><span class="block truncate">@if ($source->url)<a class="text-brand-700 hover:underline" href="{{ $source->url }}" target="_blank" rel="noopener noreferrer">{{ $source->url }}</a>@else Não informado @endif</span></x-knowledge-admin.fact>
        </x-knowledge-admin.facts>
        @if ($source->description)<p class="whitespace-pre-line border-t border-slate-100 pt-3 text-sm text-slate-700">{{ $source->description }}</p>@endif
    </x-knowledge-admin.card>

    <x-knowledge-admin.section title="Documentos recentes">
        <x-slot:actions><x-knowledge-admin.button variant="quiet" :href="route('admin.knowledge.documents.index', ['q' => $source->name])">Ver todos</x-knowledge-admin.button></x-slot:actions>
        @if ($recentDocuments->isEmpty())
            <x-knowledge-admin.empty-state>Nenhum documento desta origem ainda.</x-knowledge-admin.empty-state>
        @else
            <x-knowledge-admin.data-table :columns="[['label' => 'Título'], ['label' => 'Identificador'], ['label' => 'Criado em'], ['label' => '', 'class' => 'w-px']]">
                @foreach ($recentDocuments as $document)
                    <tr wire:key="recent-{{ $document->id }}" class="hover:bg-brand-50/40">
                        <td class="max-w-md px-3 py-2"><a class="block truncate font-semibold text-slate-900 hover:text-brand-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('admin.knowledge.documents.show', $document) }}" wire:navigate>{{ $document->title }}</a></td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ $document->external_key ?: '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ $document->created_at->format('d/m/Y H:i') }}</td>
                        <td class="px-2 py-1"><x-knowledge-admin.row-actions><x-knowledge-admin.button variant="quiet" size="sm" :href="route('admin.knowledge.documents.show', $document)">Abrir</x-knowledge-admin.button></x-knowledge-admin.row-actions></td>
                    </tr>
                @endforeach
            </x-knowledge-admin.data-table>
        @endif
    </x-knowledge-admin.section>
</x-knowledge-admin.shell>
