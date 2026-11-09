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

    @if ($naoLidos > 0)
        <x-consulta.botao variant="secondary" size="md" wire:click="marcarTodosLidos" wire:loading.attr="disabled"><x-consulta.icone name="check" plain class="size-6" />Marcar todos como lidos ({{ $naoLidos }})</x-consulta.botao>
    @endif

    <ul class="grid gap-3" aria-label="Avisos">
        @forelse ($avisos as $aviso)
            @php $lido = $aviso->read_at !== null; [$nome, $tom] = $icone((array) $aviso->data); @endphp
            <li wire:key="n-{{ $aviso->id }}">
                <article class="grid min-w-0 gap-3 rounded-2xl p-4 ring-1 transition {{ $lido ? 'bg-white ring-slate-200' : 'bg-accent-50 ring-2 ring-accent-300' }}" aria-labelledby="n-t-{{ $aviso->id }}">
                    <div class="flex min-w-0 items-start gap-3">
                        <x-consulta.icone :name="$nome" :tone="$lido ? 'neutral' : $tom" />
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-base text-slate-700">
                                <span>{{ A::quando($aviso->created_at, $fusoAtual) }}</span>
                                @unless ($lido)<x-consulta.selo tone="accent" class="bg-accent-600 text-white ring-accent-600">Novo</x-consulta.selo>@endunless
                            </p>
                            <h2 id="n-t-{{ $aviso->id }}" class="break-words text-xl font-semibold leading-snug text-brand-900">{{ $aviso->data['title'] ?? 'Aviso' }}</h2>
                            <p class="break-words text-slate-800">{{ $aviso->data['body'] ?? '' }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-consulta.botao :variant="$lido ? 'secondary' : 'primary'" size="md" class="min-w-0 flex-1 basis-48" wire:click="abrir('{{ $aviso->id }}')" wire:loading.attr="disabled">{{ A::acao($aviso) }}</x-consulta.botao>
                        <button type="button" class="inline-flex min-h-12 items-center gap-1 rounded-xl px-3 text-base font-semibold text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" wire:click="apagar('{{ $aviso->id }}')" wire:loading.attr="disabled" aria-label="Apagar aviso: {{ $aviso->data['title'] ?? 'Aviso' }}"><x-consulta.icone name="apagar" plain class="size-5" />Apagar</button>
                    </div>
                </article>
            </li>
        @empty
            <li><x-consulta.vazio icon="sino" titulo="Nenhum aviso por enquanto." texto="Quando chegar a hora de um remédio, avisamos aqui." /></li>
        @endforelse
    </ul>

    <x-consulta.cartao aria-labelledby="pref-titulo">
        <h2 id="pref-titulo" class="flex items-center gap-2 text-xl font-semibold text-brand-900"><x-consulta.icone name="sino" size="sm" />Preferências</h2>
        @if ($consentido)
            <label class="flex min-h-16 cursor-pointer items-center justify-between gap-4 rounded-2xl border border-slate-300 px-4 py-3 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50" for="pref-lembretes">
                <span class="min-w-0 text-lg font-semibold text-slate-900">Receber lembretes de horário</span>
                <input id="pref-lembretes" type="checkbox" class="size-7 shrink-0 rounded border-slate-400 text-brand-700 focus:ring-brand-600" wire:model.live="lembretes">
            </label>
            <label class="flex min-h-16 cursor-pointer items-center justify-between gap-4 rounded-2xl border border-slate-300 px-4 py-3 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50" for="pref-email">
                <span class="min-w-0"><span class="block text-lg font-semibold text-slate-900">Também por e-mail</span><span class="block break-words text-base text-slate-700">Para {{ auth()->user()->email }}</span></span>
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
            <x-consulta.botao size="md" :href="route('consulta.perfil')">Abrir meus remédios</x-consulta.botao>
        @endif

        {{-- Avisos no aparelho: com Web Push (chave VAPID configurada) chegam até com o aplicativo fechado; sem ele, só com o aplicativo aberto. --}}
        @php
            $chavePush = filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key')) ? config('services.webpush.public_key') : null;
        @endphp
        <div x-data="{
                suporte: 'Notification' in window && 'serviceWorker' in navigator,
                permissao: 'Notification' in window ? Notification.permission : 'denied',
                chave: @js($chavePush),
                push: false,
                erro: '',
                async init() { if (this.chave && window.bulaiPush && window.bulaiPush.suportado()) { this.push = await window.bulaiPush.ativo().catch(() => false) } },
                async ativar() {
                    this.erro = '';
                    this.permissao = this.permissao === 'granted' ? 'granted' : await Notification.requestPermission();
                    if (this.permissao === 'granted' && this.chave && window.bulaiPush && window.bulaiPush.suportado()) {
                        try { await window.bulaiPush.ativar(this.chave); this.push = true } catch (e) { this.erro = 'Não consegui ativar os avisos com o aplicativo fechado neste aparelho. Tente de novo.' }
                    }
                },
            }" class="grid gap-2">
            <template x-if="suporte && (permissao === 'default' || (permissao === 'granted' && chave && !push))">
                <button type="button" class="inline-flex min-h-14 w-full items-center justify-center gap-2 rounded-2xl border-2 border-brand-700 bg-white px-4 text-lg font-semibold text-brand-900 hover:bg-brand-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-600/40" x-on:click="ativar()" x-text="permissao === 'default' ? 'Ativar avisos neste aparelho' : 'Receber avisos também com o aplicativo fechado'">Ativar avisos neste aparelho</button>
            </template>
            <p x-cloak x-show="suporte && permissao === 'granted' && push" class="flex items-center gap-2 text-base text-slate-700"><x-consulta.icone name="check" plain class="size-5 shrink-0 text-emerald-700" />Avisos ativados neste aparelho, inclusive com o aplicativo fechado.</p>
            <p x-cloak x-show="suporte && permissao === 'granted' && !chave" class="flex items-center gap-2 text-base text-slate-700"><x-consulta.icone name="check" plain class="size-5 shrink-0 text-emerald-700" />Avisos neste aparelho ativados. Eles aparecem enquanto o aplicativo estiver aberto.</p>
            <p x-cloak x-show="erro !== ''" role="alert" class="rounded-lg bg-amber-50 px-3 py-2 text-base font-medium text-amber-900" x-text="erro"></p>
            <p x-cloak x-show="suporte && permissao === 'denied'" class="text-base text-slate-700">Os avisos deste aparelho estão bloqueados nas configurações do navegador.</p>
        </div>
    </x-consulta.cartao>
</x-consulta.shell>
