@php
    use App\Livewire\Consulta\Avisos as A;
    $icone = fn (array $data): array => match ((string) ($data['kind'] ?? '')) {
        'medicine_resolved' => ['check', 'success'],
        'treatment_ending' => ['calendario', 'warning'],
        'caregiver_late' => ['alerta', 'danger'],
        'caregiver_request' => ['usuario', 'brand'],
        default => ['relogio', 'accent'],
    };
@endphp
<x-consulta.shell title="Avisos" :back="route('consulta.inicio')">
    @if ($mensagem)<p role="status" class="rounded-2xl bg-brand-50 px-4 py-3 font-medium text-brand-900 ring-1 ring-brand-600/20">{{ $mensagem }}</p>@endif

    {{-- Central de avisos: ações para todos de uma vez (limpar pede confirmação). --}}
    @if ($avisos->isNotEmpty())
        <div class="flex flex-wrap items-center justify-between gap-2" x-data="{ confirmar: false }">
            <p class="px-1 text-base text-slate-700">{{ $naoLidos > 0 ? ($naoLidos === 1 ? '1 aviso novo' : $naoLidos.' avisos novos') : 'Nenhum aviso novo' }}</p>
            <div class="flex flex-wrap gap-2" x-show="!confirmar">
                @if ($naoLidos > 0)
                    <button type="button" class="inline-flex min-h-12 items-center gap-1.5 rounded-xl px-3 text-base font-semibold text-brand-800 ring-1 ring-brand-600/30 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="marcarTodosLidos" wire:loading.attr="disabled"><x-consulta.icone name="check" plain class="size-5" />Marcar todos como lidos</button>
                @endif
                <button type="button" class="inline-flex min-h-12 items-center gap-1.5 rounded-xl px-3 text-base font-semibold text-rose-800 ring-1 ring-rose-300 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600" x-on:click="confirmar = true"><x-consulta.icone name="apagar" plain class="size-5" />Limpar todos</button>
            </div>
            <div x-cloak x-show="confirmar" class="grid w-full gap-2 rounded-2xl bg-rose-50 p-3 ring-1 ring-rose-200" role="alertdialog" aria-label="Apagar todos os avisos">
                <p class="font-semibold text-rose-900">Apagar todos os {{ $avisos->count() }} avisos? Isso não muda seus remédios nem os lembretes.</p>
                <div class="flex flex-wrap gap-2">
                    <x-consulta.botao variant="danger" size="sm" class="min-w-0 flex-1 basis-40" wire:click="limparTodos" x-on:click="confirmar = false" wire:loading.attr="disabled">Sim, apagar todos</x-consulta.botao>
                    <x-consulta.botao variant="quiet" size="sm" class="min-w-0 flex-1 basis-24" x-on:click="confirmar = false">Não</x-consulta.botao>
                </div>
            </div>
        </div>
    @endif

    <ul class="grid gap-2" aria-label="Avisos">
        @forelse ($avisos as $aviso)
            @php $lido = $aviso->read_at !== null; [$nome, $tom] = $icone((array) $aviso->data); @endphp
            <li wire:key="n-{{ $aviso->id }}">
                <article class="relative grid min-w-0 grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-x-3 gap-y-2 rounded-2xl p-3 ring-1 transition sm:p-4 {{ $lido ? 'bg-white ring-slate-200' : 'bg-accent-50 ring-accent-300' }}" aria-labelledby="n-t-{{ $aviso->id }}">
                    <x-consulta.icone :name="$nome" :tone="$lido ? 'neutral' : $tom" size="sm" />
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-x-2 text-sm text-slate-600">
                            @unless ($lido)<span class="size-2.5 shrink-0 rounded-full bg-accent-600" aria-hidden="true"></span><span class="sr-only">Novo.</span>@endunless
                            <span>{{ A::quando($aviso->created_at, $fusoAtual) }}</span>
                        </p>
                        <h2 id="n-t-{{ $aviso->id }}" class="break-words text-lg font-semibold leading-snug text-brand-900">{{ $aviso->data['title'] ?? 'Aviso' }}</h2>
                        @if (filled($aviso->data['body'] ?? null))<p class="break-words text-base leading-snug text-slate-700">{{ $aviso->data['body'] }}</p>@endif
                    </div>
                    <button type="button" class="inline-flex size-11 shrink-0 items-center justify-center rounded-full text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="apagar('{{ $aviso->id }}')" wire:loading.attr="disabled" aria-label="Apagar aviso: {{ $aviso->data['title'] ?? 'Aviso' }}" title="Apagar"><x-consulta.icone name="fechar" plain class="size-5" /></button>
                    <button type="button" class="col-start-2 col-end-4 inline-flex min-h-11 items-center justify-self-start gap-1 rounded-xl px-3 text-base font-semibold focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 {{ $lido ? 'text-brand-800 ring-1 ring-brand-600/30 hover:bg-brand-50' : 'bg-brand-700 text-white hover:bg-brand-800' }}" wire:click="abrir('{{ $aviso->id }}')" wire:loading.attr="disabled">{{ A::acao($aviso) }}<span aria-hidden="true">›</span></button>
                </article>
            </li>
        @empty
            <li><x-consulta.vazio icon="sino" titulo="Nenhum aviso por enquanto." texto="Quando chegar a hora de um remédio, avisamos aqui." /></li>
        @endforelse
    </ul>

    {{-- Lembretes, e-mail, fuso e avisos no aparelho ficam em Conta (igual ao aplicativo). --}}
    <x-consulta.botao variant="soft" size="md" :href="route('consulta.conta')"><x-consulta.icone name="sino" plain class="size-6" />Lembretes e notificações: ajuste em Conta</x-consulta.botao>
</x-consulta.shell>
