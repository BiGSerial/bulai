@php
    use App\Models\CaregiverLink;
@endphp
<x-consulta.shell title="Cuidar de alguém" :back="route('consulta.inicio')">
    @if ($mensagem)<p role="status" class="rounded-2xl bg-emerald-50 px-4 py-3 font-medium text-emerald-900 ring-1 ring-emerald-300">{{ $mensagem }}</p>@endif

    <x-consulta.cartao aria-labelledby="codigo-titulo">
        <h2 id="codigo-titulo" class="flex items-center gap-2 text-xl font-semibold text-brand-900"><x-consulta.icone name="usuario" size="sm" />Tenho um código de convite</h2>
        <p class="text-slate-800">Peça para a pessoa que você cuida criar um código em “Quem cuida de mim”. Depois que você digitar, ela precisa aprovar.</p>
        <form class="grid gap-3" wire:submit="usarCodigo">
            <div>
                <label class="font-medium text-slate-800" for="codigo">Código de 8 números</label>
                <input id="codigo" class="ui-control min-h-16 text-center text-3xl font-bold tracking-widest tabular-nums" wire:model="codigo" inputmode="numeric" autocomplete="one-time-code" maxlength="12" placeholder="0000 0000" @if ($erro) aria-invalid="true" aria-describedby="codigo-erro" @endif>
                @if ($erro)<p id="codigo-erro" role="alert" class="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-lg font-medium text-amber-900 ring-1 ring-amber-300">{{ $erro }}</p>@endif
            </div>
            <x-consulta.botao type="submit" variant="accent" loadingTarget="usarCodigo" loading="Conferindo…">Usar o código</x-consulta.botao>
        </form>
    </x-consulta.cartao>

    @foreach ($pendentes as $vinculo)
        <x-consulta.cartao tone="accent" wire:key="pend-{{ $vinculo->id }}">
            <p class="break-words text-xl font-semibold leading-snug text-slate-900">Aguardando {{ $vinculo->patient->name }} aprovar</p>
            <p class="text-base text-slate-700">Assim que for aprovado, os remédios aparecem aqui conforme o que for liberado.</p>
            <x-consulta.botao variant="quiet" size="sm" wire:click="sair({{ $vinculo->id }})" wire:loading.attr="disabled">Desistir do pedido</x-consulta.botao>
        </x-consulta.cartao>
    @endforeach

    <section class="grid gap-3" aria-labelledby="cuido-titulo">
        <h2 id="cuido-titulo" class="px-1 text-xl font-semibold text-brand-900">Pessoas que você acompanha</h2>
        @forelse ($ativos as $vinculo)
            @php $nome = CaregiverLink::firstName($vinculo->patient); $consentiu = $vinculo->patient->hasHealthConsent(); @endphp
            <x-consulta.cartao wire:key="cuido-{{ $vinculo->id }}" x-data="{ confirmar: false }">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="inline-flex size-12 shrink-0 items-center justify-center rounded-full bg-accent-100 text-xl font-semibold text-accent-800 ring-1 ring-accent-300" aria-hidden="true">{{ mb_strtoupper(mb_substr($vinculo->patient->name, 0, 1)) }}</span>
                    <p class="min-w-0 break-words text-xl font-semibold leading-snug text-slate-900">{{ $vinculo->patient->name }}</p>
                </div>
                <ul class="grid gap-1 text-base text-slate-800" aria-label="O que {{ $nome }} liberou">
                    @foreach ($frases as $chave => [$sim, $nao])
                        <li class="flex items-start gap-2">
                            @if ($vinculo->{$chave})
                                <x-consulta.icone name="check" plain class="mt-0.5 size-5 shrink-0 text-emerald-700" /><span class="min-w-0 break-words">{{ $sim }}</span>
                            @else
                                <x-consulta.icone name="fechar" plain class="mt-0.5 size-5 shrink-0 text-slate-500" /><span class="min-w-0 break-words text-slate-600">{{ $nao }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @unless ($consentiu)
                    <p class="rounded-2xl bg-amber-50 px-4 py-3 text-base font-medium text-amber-900 ring-1 ring-amber-300" role="note">{{ $nome }} ainda não autorizou guardar dados de saúde no BULA-IA (ou apagou esses dados). Sem essa autorização, nada é mostrado e nenhum aviso é enviado.</p>
                @endunless
                @if ($consentiu && $vinculo->can_view_medications)
                    <x-consulta.botao :href="route('consulta.cuidando.hoje', $vinculo)"><x-consulta.icone name="relogio" plain class="size-7" />Remédios de hoje de {{ $nome }}</x-consulta.botao>
                    <x-consulta.botao variant="soft" size="md" :href="route('consulta.cuidando.remedios', $vinculo)"><x-consulta.icone name="remedios" plain class="size-6" />Lista de remédios de {{ $nome }}</x-consulta.botao>
                @endif
                <button type="button" x-show="!confirmar" class="inline-flex min-h-12 w-full items-center justify-center rounded-2xl px-4 text-base font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-rose-600/30" x-on:click="confirmar = true">Deixar de acompanhar</button>
                <div x-cloak x-show="confirmar" class="grid gap-2">
                    <p class="font-semibold text-rose-900">Deixar de acompanhar {{ $nome }}? Para voltar, será preciso um convite novo.</p>
                    <x-consulta.botao variant="danger" size="md" wire:click="sair({{ $vinculo->id }})" wire:loading.attr="disabled">Sim, deixar de acompanhar</x-consulta.botao>
                    <x-consulta.botao variant="quiet" size="sm" x-on:click="confirmar = false">Não</x-consulta.botao>
                </div>
            </x-consulta.cartao>
        @empty
            <x-consulta.vazio icon="usuario" titulo="Você ainda não acompanha ninguém." texto="Quando a pessoa aprovar o seu pedido, ela aparece aqui." />
        @endforelse
    </section>
</x-consulta.shell>
