<x-knowledge-admin.shell :title="$source->displayName()" :subtitle="$source->documents_count.' '.($source->documents_count === 1 ? 'documento' : 'documentos').' · '.$source->batches_count.' '.($source->batches_count === 1 ? 'importação' : 'importações')" :back="route('admin.knowledge.sources.index')" back-label="Origens">
    <x-slot:actions>
        <x-knowledge-admin.badge :tone="$source->active ? 'success' : 'neutral'">{{ $source->active ? 'Ativa' : 'Inativa' }}</x-knowledge-admin.badge>
        <x-knowledge-admin.button variant="secondary" wire:click="toggleActive" loading-target="toggleActive">{{ $source->active ? 'Desativar' : 'Ativar' }}</x-knowledge-admin.button>
        <x-knowledge-admin.button :href="route('admin.knowledge.sources.edit', $source)">Editar</x-knowledge-admin.button>
    </x-slot:actions>

    @if (session('sourceFeedback'))<x-knowledge-admin.alert tone="success">{{ session('sourceFeedback') }}</x-knowledge-admin.alert>@endif

    <x-knowledge-admin.section title="Detalhes">
        <x-knowledge-admin.facts>
            <x-knowledge-admin.fact label="Tipo">{{ $typeLabel }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="País">{{ $source->country ?: 'Não informado' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact class="sm:col-span-2" label="URL oficial">@if ($source->url)<a class="text-brand-700 underline" href="{{ $source->url }}" target="_blank" rel="noopener noreferrer">{{ $source->url }}</a>@else Não informada @endif</x-knowledge-admin.fact>
            <x-knowledge-admin.fact class="sm:col-span-2" label="Descrição"><span class="whitespace-pre-line">{{ $source->description ?: 'Sem descrição.' }}</span></x-knowledge-admin.fact>
        </x-knowledge-admin.facts>
    </x-knowledge-admin.section>

    <x-knowledge-admin.section title="Documentos recentes">
        <div class="grid gap-2">
            @forelse ($recentDocuments as $document)
                <a class="rounded-lg border border-slate-200 px-4 py-3 transition hover:border-brand-600" href="{{ route('admin.knowledge.documents.show', $document) }}" wire:navigate><span class="block text-sm font-semibold text-slate-900">{{ $document->title }}</span><span class="block text-sm text-slate-500">{{ $document->external_key }} · {{ $document->created_at->format('d/m/Y H:i') }}</span></a>
            @empty
                <x-knowledge-admin.empty-state>Nenhum documento associado.</x-knowledge-admin.empty-state>
            @endforelse
        </div>
    </x-knowledge-admin.section>
</x-knowledge-admin.shell>
