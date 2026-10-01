<x-knowledge-admin.shell title="Visão geral">
    <div class="flex justify-end"><a class="rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-teal-800" href="{{ route('admin.knowledge.upload') }}" wire:navigate>Adicionar documentos</a></div>
    <section class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <x-knowledge-admin.metric-card label="Documentos" :value="$metrics['documents']" :href="route('admin.knowledge.documents.index')" />
        <x-knowledge-admin.metric-card label="Documentos com PDF" :value="$metrics['documentsWithFile']" :href="route('admin.knowledge.documents.index')" />
        <x-knowledge-admin.metric-card label="Documentos sem PDF" :value="$metrics['documentsWithoutFile']" :href="route('admin.knowledge.documents.index')" />
        <x-knowledge-admin.metric-card label="Lotes processados" :value="$metrics['batches']" :href="route('admin.knowledge.batches.index')" />
        <x-knowledge-admin.metric-card label="Itens pendentes" :value="$metrics['pending']" :href="route('admin.knowledge.pending.index')" />
        <x-knowledge-admin.metric-card label="Itens com erro" :value="$metrics['errors']" :href="route('admin.knowledge.batches.index')" />
    </section>
</x-knowledge-admin.shell>
