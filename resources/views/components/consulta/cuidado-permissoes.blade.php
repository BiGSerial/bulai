{{-- Caixas de permissão de um vínculo de cuidado (modo cuidador). Os valores ficam em `permissoes.{id}.{chave}` do componente Livewire. Marcar e editar exige também ver: o servidor garante. --}}
@props(['id', 'rotulos'])
@foreach ($rotulos as $chave => $rotulo)
    <label class="flex min-h-14 cursor-pointer items-center gap-3 rounded-2xl border border-slate-300 bg-white px-4 py-2 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50" for="perm-{{ $id }}-{{ $chave }}">
        <input id="perm-{{ $id }}-{{ $chave }}" type="checkbox" class="size-7 shrink-0 rounded border-slate-400 text-brand-700 focus:ring-brand-600" wire:model="permissoes.{{ $id }}.{{ $chave }}">
        <span class="min-w-0 break-words text-lg text-slate-900">{{ $rotulo }}</span>
    </label>
@endforeach
<p class="text-base text-slate-600">Para marcar doses e mudar horários, a pessoa também precisa ver seus remédios.</p>
