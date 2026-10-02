<x-knowledge-admin.shell title="Visão geral" subtitle="Situação atual da base de documentos.">
    <x-slot:actions><x-knowledge-admin.button :href="route('admin.knowledge.upload')">Adicionar documentos</x-knowledge-admin.button></x-slot:actions>

    @if ($metrics['pending'] > 0)
        <x-knowledge-admin.alert tone="warning">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span>{{ $metrics['pending'] }} {{ $metrics['pending'] === 1 ? 'arquivo aguarda' : 'arquivos aguardam' }} sua revisão para entrar na base.</span>
                <x-knowledge-admin.button variant="secondary" :href="route('admin.knowledge.pending.index')">Revisar pendências</x-knowledge-admin.button>
            </div>
        </x-knowledge-admin.alert>
    @endif

    <section class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-knowledge-admin.stat label="Documentos" :value="$metrics['documents']" :hint="$metrics['documentsWithFile'].' com PDF · '.$metrics['documentsWithoutFile'].' sem PDF'" :href="route('admin.knowledge.documents.index')" />
        <x-knowledge-admin.stat label="Importações" :value="$metrics['batches']" :href="route('admin.knowledge.batches.index')" />
        <x-knowledge-admin.stat label="Pendências" :value="$metrics['pending']" :tone="$metrics['pending'] > 0 ? 'warning' : 'default'" :href="route('admin.knowledge.pending.index')" />
        <x-knowledge-admin.stat label="Arquivos com erro" :value="$metrics['errors']" :tone="$metrics['errors'] > 0 ? 'danger' : 'default'" :href="route('admin.knowledge.batches.index')" />
    </section>
</x-knowledge-admin.shell>
