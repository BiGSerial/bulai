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

    <x-knowledge-admin.card>
        <dl class="grid gap-4 sm:grid-cols-3">
            <div><dt class="text-xs text-slate-500">Documentos</dt><dd class="mt-0.5 text-base font-semibold text-slate-900">{{ $source->documents_count }}</dd></div>
            <div><dt class="text-xs text-slate-500">Importações</dt><dd class="mt-0.5 text-base font-semibold text-slate-900">{{ $source->batches_count }}</dd></div>
            <div class="min-w-0"><dt class="text-xs text-slate-500">Endereço oficial</dt><dd class="mt-0.5 truncate text-base font-semibold text-slate-900">@if ($source->url)<a class="text-brand-700 hover:underline" href="{{ $source->url }}" target="_blank" rel="noopener noreferrer">{{ $source->url }}</a>@else Não informado @endif</dd></div>
        </dl>
        @if ($source->description)<p class="mt-4 whitespace-pre-line border-t border-slate-100 pt-4 text-sm text-slate-700">{{ $source->description }}</p>@endif
    </x-knowledge-admin.card>

    <x-knowledge-admin.section title="Documentos recentes">
        <div class="grid gap-2">
            @forelse ($recentDocuments as $document)
                <a wire:key="recent-{{ $document->id }}" class="flex items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 transition hover:border-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('admin.knowledge.documents.show', $document) }}" wire:navigate><span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-slate-900">{{ $document->title }}</span><span class="block truncate text-xs text-slate-500">{{ $document->external_key }} · {{ $document->created_at->format('d/m/Y H:i') }}</span></span><span class="shrink-0 text-sm font-semibold text-brand-700">Abrir</span></a>
            @empty
                <x-knowledge-admin.empty-state>Nenhum documento desta origem ainda.</x-knowledge-admin.empty-state>
            @endforelse
        </div>
    </x-knowledge-admin.section>
</x-knowledge-admin.shell>
