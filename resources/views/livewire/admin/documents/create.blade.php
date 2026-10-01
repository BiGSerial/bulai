<x-knowledge-admin.shell title="Criar Documento de Conhecimento">
    <div><a class="text-sm font-semibold text-teal-700" href="{{ route('admin.knowledge.documents.index') }}" wire:navigate>← Voltar aos documentos</a></div>
    <form wire:submit="save" class="max-w-3xl rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-lg font-semibold text-slate-950">Identidade lógica</h2>
        <p class="mt-1 text-sm text-slate-500">Cadastre o documento, não o arquivo físico. Um PDF poderá ser associado pela ingestão.</p>
        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <label class="grid gap-1 text-sm font-semibold text-slate-700 sm:col-span-2">Origem *<select wire:model="sourceId" class="rounded-md border border-slate-300 px-3 py-2 font-normal"><option value="">Selecione…</option>@foreach($sources as $source)<option value="{{ $source->id }}">{{ $source->displayName() }}</option>@endforeach</select></label>
            <label class="grid gap-1 text-sm font-semibold text-slate-700 sm:col-span-2">Título *<input wire:model="title" class="rounded-md border border-slate-300 px-3 py-2 font-normal"></label>
            <label class="grid gap-1 text-sm font-semibold text-slate-700">Tipo *<select wire:model="documentType" class="rounded-md border border-slate-300 px-3 py-2 font-normal"><option value="bula">Bula</option><option value="bula_paciente">Bula do paciente</option><option value="bula_profissional">Bula do profissional</option><option value="documento_regulatorio">Documento regulatório</option><option value="outro">Outro</option></select></label>
            <label class="grid gap-1 text-sm font-semibold text-slate-700">Identificador oficial<input wire:model="sourceDocumentId" class="rounded-md border border-slate-300 px-3 py-2 font-normal"></label>
            <label class="grid gap-1 text-sm font-semibold text-slate-700 sm:col-span-2">Chave externa (opcional)<input wire:model="externalKey" class="rounded-md border border-slate-300 px-3 py-2 font-normal"></label>
        </div>
        <div class="mt-3">@foreach($errors->all() as $error)<p class="text-sm text-rose-700">{{ $error }}</p>@endforeach</div>
        <button type="submit" wire:loading.attr="disabled" class="mt-5 rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:bg-slate-400">Criar documento</button>
    </form>
</x-knowledge-admin.shell>
