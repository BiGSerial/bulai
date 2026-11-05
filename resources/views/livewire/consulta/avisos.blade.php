@php use App\Livewire\Consulta\Avisos as A; @endphp
<x-consulta.shell title="Avisos" :back="route('consulta.inicio')">
    @if ($mensagem)<p role="status" class="rounded-xl bg-brand-50 px-4 py-3 font-medium text-brand-900">{{ $mensagem }}</p>@endif

    @if ($naoLidos > 0)
        <button type="button" class="inline-flex min-h-14 w-full items-center justify-center rounded-xl border-2 border-brand-700 bg-white px-4 text-lg font-semibold text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" wire:click="marcarTodosLidos" wire:loading.attr="disabled">Marcar todos como lidos ({{ $naoLidos }})</button>
    @endif

    <ul class="grid gap-3" aria-label="Avisos">
        @forelse ($avisos as $aviso)
            @php $lido = $aviso->read_at !== null; @endphp
            <li wire:key="n-{{ $aviso->id }}">
                <article class="grid gap-3 rounded-2xl p-4 ring-1 {{ $lido ? 'bg-white ring-slate-200' : 'bg-accent-50 ring-accent-300' }}" aria-labelledby="n-t-{{ $aviso->id }}">
                    <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-base text-slate-700">
                        <span>{{ A::quando($aviso->created_at, $fusoAtual) }}</span>
                        @unless ($lido)<span class="rounded-full bg-accent-600 px-3 py-0.5 text-base font-semibold text-white">Novo</span>@endunless
                    </p>
                    <h2 id="n-t-{{ $aviso->id }}" class="text-xl font-semibold leading-snug text-brand-900">{{ $aviso->data['title'] ?? 'Aviso' }}</h2>
                    <p class="text-slate-800">{{ $aviso->data['body'] ?? '' }}</p>
                    <button type="button" class="inline-flex min-h-14 w-full items-center justify-center rounded-xl px-4 text-lg font-semibold focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40 {{ $lido ? 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50' : 'bg-brand-700 text-white hover:bg-brand-800' }}" wire:click="abrir('{{ $aviso->id }}')" wire:loading.attr="disabled">{{ A::acao($aviso) }}</button>
                    <button type="button" class="min-h-12 justify-self-start rounded-xl px-3 text-base font-semibold text-slate-600 underline hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="apagar('{{ $aviso->id }}')" wire:loading.attr="disabled" aria-label="Apagar aviso: {{ $aviso->data['title'] ?? 'Aviso' }}">Apagar</button>
                </article>
            </li>
        @empty
            <li class="rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-6 text-center text-slate-700">Nenhum aviso por enquanto. Quando chegar a hora de um remédio, avisamos aqui.</li>
        @endforelse
    </ul>

    <section class="grid gap-4 rounded-2xl bg-white p-4 ring-1 ring-slate-200" aria-labelledby="pref-titulo">
        <h2 id="pref-titulo" class="text-xl font-semibold text-brand-900">Preferências</h2>
        @if ($consentido)
            <label class="flex min-h-16 cursor-pointer items-center justify-between gap-4 rounded-xl border border-slate-300 px-4 py-3 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50" for="pref-lembretes">
                <span class="text-lg font-semibold text-slate-900">Receber lembretes de horário</span>
                <input id="pref-lembretes" type="checkbox" class="size-7 shrink-0 rounded border-slate-400 text-brand-700 focus:ring-brand-600" wire:model.live="lembretes">
            </label>
            <label class="flex min-h-16 cursor-pointer items-center justify-between gap-4 rounded-xl border border-slate-300 px-4 py-3 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50" for="pref-email">
                <span class="min-w-0"><span class="block text-lg font-semibold text-slate-900">Também por e-mail</span><span class="block text-base text-slate-700">Para {{ auth()->user()->email }}</span></span>
                <input id="pref-email" type="checkbox" class="size-7 shrink-0 rounded border-slate-400 text-brand-700 focus:ring-brand-600" wire:model.live="email">
            </label>
            <div class="grid gap-2">
                <label class="text-lg font-semibold text-slate-900" for="pref-fuso">Horário da sua região</label>
                <select id="pref-fuso" class="ui-control min-h-14 text-lg" wire:model.live="fuso">
                    @foreach ($fusos as $valor => $rotulo)<option value="{{ $valor }}">{{ $rotulo }}</option>@endforeach
                </select>
            </div>
        @else
            <p class="text-slate-800">Para receber lembretes de horário, aceite guardar seus dados de saúde em “Meus remédios”.</p>
            <a class="inline-flex min-h-14 items-center justify-center rounded-xl bg-brand-700 px-4 text-lg font-semibold text-white hover:bg-brand-800 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" href="{{ route('consulta.perfil') }}" wire:navigate>Abrir meus remédios</a>
        @endif

        {{-- Avisos do navegador (só com o aplicativo aberto; sem Web Push). Aparece apenas onde há suporte e ainda não foi permitido. --}}
        <div x-data="{ suporte: 'Notification' in window && 'serviceWorker' in navigator, permissao: 'Notification' in window ? Notification.permission : 'denied' }" class="grid gap-2">
            <template x-if="suporte && permissao === 'default'">
                <button type="button" class="inline-flex min-h-14 w-full items-center justify-center rounded-xl border-2 border-brand-700 bg-white px-4 text-lg font-semibold text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" x-on:click="Notification.requestPermission().then((r) => permissao = r)">Ativar avisos neste aparelho</button>
            </template>
            <p x-cloak x-show="suporte && permissao === 'granted'" class="text-base text-slate-700">Avisos neste aparelho ativados. Eles aparecem enquanto o aplicativo estiver aberto.</p>
            <p x-cloak x-show="suporte && permissao === 'denied'" class="text-base text-slate-700">Os avisos deste aparelho estão bloqueados nas configurações do navegador.</p>
        </div>
    </section>
</x-consulta.shell>
