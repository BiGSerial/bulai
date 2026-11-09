<x-consulta.shell :title="'Remédios de '.$nome" :back="route('consulta.cuidando.hoje', $vinculo)" :conta="true" :cuidando="$vinculo">
    @if ($mensagem)<p role="status" class="rounded-2xl bg-brand-50 px-4 py-3 font-medium text-brand-900 ring-1 ring-brand-600/20">{{ $mensagem }}</p>@endif
    @unless ($podeEditar)
        <p class="flex items-start gap-2 rounded-2xl bg-slate-100 px-4 py-3 text-base text-slate-800" role="note"><x-consulta.icone name="info" plain class="mt-0.5 size-5 shrink-0" /><span class="min-w-0 break-words">Você pode ver os remédios, mas {{ $nome }} não liberou mudar dose e horários.</span></p>
    @endunless

    <ul class="grid gap-3" aria-label="Remédios de {{ $nome }}">
        @forelse ($remedios as $item)
            <li wire:key="rem-{{ $item->id }}">
                <article class="grid min-w-0 gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200">
                    <div class="flex min-w-0 items-start gap-3">
                        <x-consulta.icone name="pilula" />
                        <div class="min-w-0 flex-1">
                            <p class="break-words text-xl font-semibold leading-snug text-slate-900">{{ $item->displayName() }}</p>
                            <p class="break-words text-base text-slate-700">
                                @if ($item->dosage || $item->frequency){{ collect([$item->dosage, $item->frequency])->filter()->join(' · ') }}@else Posologia não informada @endif
                                @if ($item->ends_at) · até {{ $item->ends_at->format('d/m/Y') }}@endif
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($item->as_needed)
                            <x-consulta.selo tone="neutral">Sem horário fixo</x-consulta.selo>
                        @elseif ($item->isDynamic())
                            <x-consulta.selo tone="brand">{{ $item->intervalLabel() }} · ajusta pelo horário em que toma</x-consulta.selo>
                        @else
                            @foreach ($item->scheduledTimes() as $hora)<x-consulta.selo tone="brand" class="tabular-nums">{{ $hora }}</x-consulta.selo>@endforeach
                            @if ($item->scheduledTimes() === [])<x-consulta.selo tone="neutral">Sem horários</x-consulta.selo>@endif
                        @endif
                        @unless ($item->isActive())<x-consulta.selo tone="neutral">Tratamento encerrado</x-consulta.selo>@endunless
                    </div>
                    @if ($podeEditar)
                        @if ($editandoId === $item->id)
                            <x-consulta.form-posologia :item="$item" :posologia="$posologia" resumo="Datas e observações" />
                        @else
                            <x-consulta.botao variant="soft" size="md" wire:click="editarPosologia({{ $item->id }})" aria-label="Mudar dose e horários de {{ $item->displayName() }}"><x-consulta.icone name="editar" plain class="size-6" />Mudar dose e horários</x-consulta.botao>
                        @endif
                    @endif
                </article>
            </li>
        @empty
            <li><x-consulta.vazio icon="pilula" titulo="Nenhum remédio registrado." :texto="$nome.' ainda não cadastrou remédios.'" /></li>
        @endforelse
    </ul>

    <x-consulta.botao variant="soft" size="md" :href="route('consulta.cuidando.hoje', $vinculo)"><x-consulta.icone name="relogio" plain class="size-6" />Remédios de hoje de {{ $nome }}</x-consulta.botao>
</x-consulta.shell>
