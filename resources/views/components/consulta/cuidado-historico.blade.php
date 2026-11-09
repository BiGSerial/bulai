{{-- Histórico de um vínculo de cuidado (auditoria LGPD): o que aconteceu, quem fez e quando, no fuso do paciente. --}}
@props(['eventos', 'fuso'])
<ol class="grid gap-2 rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200" aria-label="Histórico">
    @forelse ($eventos as $evento)
        <li wire:key="hist-{{ $evento->id }}" class="grid min-w-0 gap-0.5 border-b border-slate-200 pb-2 last:border-b-0 last:pb-0">
            <span class="text-base text-slate-600">{{ \App\Livewire\Consulta\Avisos::quando($evento->created_at, $fuso) }}</span>
            <span class="break-words text-lg text-slate-900">{{ $evento->describe() }}</span>
        </li>
    @empty
        <li class="text-base text-slate-700">Nada registrado ainda.</li>
    @endforelse
</ol>
