@php
    use App\Models\CaregiverLink;
@endphp
<x-consulta.shell title="Quem cuida de mim" :back="route('consulta.inicio')">
    @if ($mensagem)<p role="status" class="rounded-2xl bg-brand-50 px-4 py-3 font-medium text-brand-900 ring-1 ring-brand-600/20">{{ $mensagem }}</p>@endif
    @if ($erro)<p role="alert" class="rounded-2xl bg-amber-50 px-4 py-3 font-medium text-amber-900 ring-1 ring-amber-300">{{ $erro }}</p>@endif

    <x-consulta.cartao tone="brand">
        <h2 class="flex items-center gap-2 text-xl font-semibold text-brand-900"><x-consulta.icone name="usuario" size="sm" />Um familiar pode acompanhar seus remédios</h2>
        <p class="text-slate-800">Quem cuida de você pode ver seus horários, receber um aviso quando um remédio atrasar e, se você deixar, marcar as doses. Você escolhe o que cada pessoa pode fazer e pode tirar o acesso quando quiser.</p>
        <p class="text-base text-slate-700">Só mostramos dados de saúde porque você autorizou guardá-los. Se você apagar seus dados de saúde, quem cuida deixa de ver tudo na mesma hora.</p>
    </x-consulta.cartao>

    @unless ($consentido)
        <x-consulta.cartao tone="warning">
            <p class="text-xl font-semibold leading-tight">Para convidar alguém, primeiro autorize guardar seus dados de saúde.</p>
            <x-consulta.botao :href="route('consulta.perfil')">Abrir Meus remédios</x-consulta.botao>
        </x-consulta.cartao>
    @else
        {{-- Convite: código de 8 números, de uso único, vale por 48 horas. --}}
        <x-consulta.cartao aria-labelledby="convite-titulo">
            <h2 id="convite-titulo" class="text-xl font-semibold text-brand-900">Convidar alguém</h2>
            @if ($convite)
                @php $link = route('consulta.cuidar', ['codigo' => $convite->invite_code]); $codigo = CaregiverLink::formatCode((string) $convite->invite_code); @endphp
                <p class="text-slate-800">Passe este código para quem vai cuidar de você. A pessoa precisa ter conta no BULA-IA e digitar o código em “Cuidar de alguém”.</p>
                <p class="rounded-2xl bg-brand-50 px-4 py-4 text-center text-4xl font-bold tracking-widest tabular-nums text-brand-900" aria-label="Código do convite: {{ implode(' ', str_split((string) $convite->invite_code)) }}" data-codigo>{{ $codigo }}</p>
                @php $vence = $convite->invite_expires_at->copy()->timezone($fuso); $hojeLocal = now($fuso); @endphp
                <p class="text-center text-base text-slate-700">Vale até {{ $vence->isSameDay($hojeLocal) ? 'hoje' : ($vence->isSameDay($hojeLocal->copy()->addDay()) ? 'amanhã' : $vence->format('d/m')) }} às {{ $vence->format('H:i') }} e só pode ser usado uma vez.</p>
                <div x-data="{ copiado: false }" class="grid gap-2">
                    <x-consulta.botao variant="accent" size="md" x-on:click="const dados = { title: 'Convite do BULA-IA', text: 'Use o código {{ $codigo }} para acompanhar meus remédios no BULA-IA.', url: @js($link) }; if (navigator.share) { navigator.share(dados).catch(() => {}) } else if (navigator.clipboard) { navigator.clipboard.writeText(dados.text + ' ' + dados.url).then(() => copiado = true) }">Enviar o convite</x-consulta.botao>
                    <p x-cloak x-show="copiado" role="status" class="text-center text-base font-semibold text-emerald-800">Convite copiado. Cole na conversa com a pessoa.</p>
                    <x-consulta.botao variant="quiet" size="sm" wire:click="cancelarConvite" wire:loading.attr="disabled">Cancelar este convite</x-consulta.botao>
                </div>
            @else
                @if ($conviteVencido)<p class="text-base text-slate-700">O último convite venceu sem ser usado.</p>@endif
                <p class="text-slate-800">Vamos criar um código de 8 números. Ele vale por 48 horas e só pode ser usado uma vez. Nada é mostrado antes de você aprovar.</p>
                <x-consulta.botao variant="accent" wire:click="gerarConvite" loadingTarget="gerarConvite" loading="Criando…"><x-consulta.icone name="mais" plain class="size-6" />Criar código de convite</x-consulta.botao>
            @endif
        </x-consulta.cartao>
    @endunless

    @foreach ($pendentes as $vinculo)
        <x-consulta.cartao tone="accent" wire:key="pend-{{ $vinculo->id }}" aria-labelledby="pend-t-{{ $vinculo->id }}">
            <h2 id="pend-t-{{ $vinculo->id }}" class="break-words text-xl font-semibold text-brand-900">{{ $vinculo->caregiver->name }} quer acompanhar você</h2>
            <p class="break-words text-base text-slate-700">{{ $vinculo->caregiver->email }} usou o seu código. Confira se é a pessoa certa e escolha o que ela pode fazer:</p>
            <fieldset class="grid gap-2">
                <legend class="sr-only">Permissões de {{ $vinculo->caregiver->name }}</legend>
                <x-consulta.cuidado-permissoes :id="$vinculo->id" :rotulos="$rotulos" />
            </fieldset>
            <x-consulta.botao variant="success" wire:click="aprovar({{ $vinculo->id }})" loadingTarget="aprovar({{ $vinculo->id }})" loading="Aprovando…"><x-consulta.icone name="check" plain class="size-7" />Aprovar</x-consulta.botao>
            <x-consulta.botao variant="secondary" size="md" wire:click="recusar({{ $vinculo->id }})" wire:loading.attr="disabled">Recusar</x-consulta.botao>
        </x-consulta.cartao>
    @endforeach

    <section class="grid gap-3" aria-labelledby="ativos-titulo">
        <h2 id="ativos-titulo" class="px-1 text-xl font-semibold text-brand-900">Pessoas que cuidam de você</h2>
        @forelse ($ativos as $vinculo)
            <x-consulta.cartao wire:key="ativo-{{ $vinculo->id }}" x-data="{ confirmar: false }">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="inline-flex size-12 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xl font-semibold text-brand-900 ring-1 ring-brand-600/20" aria-hidden="true">{{ mb_strtoupper(mb_substr($vinculo->caregiver->name, 0, 1)) }}</span>
                    <div class="min-w-0">
                        <p class="break-words text-xl font-semibold leading-snug text-slate-900">{{ $vinculo->caregiver->name }}</p>
                        <p class="break-words text-base text-slate-600">Acompanha desde {{ $vinculo->approved_at?->copy()->timezone($fuso)->format('d/m/Y') }}</p>
                    </div>
                </div>
                <fieldset class="grid gap-2">
                    <legend class="mb-1 font-medium text-slate-800">O que {{ CaregiverLink::firstName($vinculo->caregiver) }} pode fazer</legend>
                    <x-consulta.cuidado-permissoes :id="$vinculo->id" :rotulos="$rotulos" />
                </fieldset>
                <x-consulta.botao size="md" wire:click="salvarPermissoes({{ $vinculo->id }})" loadingTarget="salvarPermissoes({{ $vinculo->id }})" loading="Salvando…">Salvar permissões</x-consulta.botao>
                <x-consulta.botao variant="soft" size="md" wire:click="alternarHistorico({{ $vinculo->id }})">{{ $historicoDe === $vinculo->id ? 'Esconder o histórico' : 'Ver o histórico' }}</x-consulta.botao>
                @if ($historicoDe === $vinculo->id)
                    <x-consulta.cuidado-historico :eventos="$historico" :fuso="$fuso" />
                @endif
                <button type="button" x-show="!confirmar" class="inline-flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl border border-rose-300 bg-white px-4 text-lg font-semibold text-rose-800 hover:bg-rose-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-rose-600/30" x-on:click="confirmar = true">Tirar o acesso</button>
                <div x-cloak x-show="confirmar" class="grid gap-2">
                    <p class="font-semibold text-rose-900">Tirar o acesso de {{ $vinculo->caregiver->name }}? Essa pessoa deixa de ver seus remédios e de receber avisos na mesma hora.</p>
                    <x-consulta.botao variant="danger" size="md" wire:click="revogar({{ $vinculo->id }})" wire:loading.attr="disabled">Sim, tirar o acesso</x-consulta.botao>
                    <x-consulta.botao variant="quiet" size="sm" x-on:click="confirmar = false">Não</x-consulta.botao>
                </div>
            </x-consulta.cartao>
        @empty
            <x-consulta.vazio icon="usuario" titulo="Ninguém acompanha você ainda." texto="Crie um código de convite e passe para a pessoa de confiança." />
        @endforelse
    </section>

    @if ($encerrados->isNotEmpty())
        <x-consulta.secao titulo="Acessos encerrados" resumo="Quem já acompanhou você e o histórico" icon="historico" tone="neutral">
            @foreach ($encerrados as $vinculo)
                <div class="grid gap-2" wire:key="enc-{{ $vinculo->id }}">
                    <p class="break-words text-lg text-slate-900"><span class="font-semibold">{{ $vinculo->caregiver->name }}</span> · {{ $vinculo->status === CaregiverLink::STATUS_LEFT ? 'saiu' : 'acesso retirado' }} em {{ $vinculo->ended_at?->copy()->timezone($fuso)->format('d/m/Y') }}</p>
                    <x-consulta.botao variant="quiet" size="sm" wire:click="alternarHistorico({{ $vinculo->id }})">{{ $historicoDe === $vinculo->id ? 'Esconder o histórico' : 'Ver o histórico' }}</x-consulta.botao>
                    @if ($historicoDe === $vinculo->id)
                        <x-consulta.cuidado-historico :eventos="$historico" :fuso="$fuso" />
                    @endif
                </div>
            @endforeach
        </x-consulta.secao>
    @endif
</x-consulta.shell>
