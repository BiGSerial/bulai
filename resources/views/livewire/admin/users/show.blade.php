<x-knowledge-admin.shell :title="$user->name" :subtitle="$user->email.' · '.$user->role->label()" :back="route('admin.users.index')" back-label="Usuários">
    <x-slot:actions>
        @if (! $user->isActive())
            <x-knowledge-admin.badge>Inativo</x-knowledge-admin.badge>
        @elseif ($user->must_change_password)
            <x-knowledge-admin.badge tone="warning">Troca de senha pendente</x-knowledge-admin.badge>
        @else
            <x-knowledge-admin.badge tone="success">Ativo</x-knowledge-admin.badge>
        @endif
        <x-knowledge-admin.button :href="route('admin.users.edit', $user)">Editar</x-knowledge-admin.button>
    </x-slot:actions>

    @if (session('userFeedback'))<x-knowledge-admin.alert tone="success">{{ session('userFeedback') }}</x-knowledge-admin.alert>@endif
    @if ($feedback)<x-knowledge-admin.alert tone="success">{{ $feedback }}</x-knowledge-admin.alert>@endif
    @error('active')<x-knowledge-admin.alert tone="danger">{{ $message }}</x-knowledge-admin.alert>@enderror
    @error('temporaryPassword')<x-knowledge-admin.alert tone="danger">{{ $message }}</x-knowledge-admin.alert>@enderror
    @error('mustChangePassword')<x-knowledge-admin.alert tone="danger">{{ $message }}</x-knowledge-admin.alert>@enderror
    @if ($isCurrentUser)<x-knowledge-admin.alert>Este é o seu acesso. Para trocar a sua senha ou encerrar outras sessões, use <a class="font-medium underline" href="{{ route('account') }}">Minha conta</a>.</x-knowledge-admin.alert>@endif

    <x-knowledge-admin.facts class="sm:grid-cols-4">
        <x-knowledge-admin.fact label="Situação">{{ $user->isActive() ? 'Ativo' : 'Inativo' }}</x-knowledge-admin.fact>
        <x-knowledge-admin.fact label="Troca de senha">{{ $user->must_change_password ? 'Pendente (usa senha provisória)' : 'Em dia' }}</x-knowledge-admin.fact>
        <x-knowledge-admin.fact label="Último acesso">{{ $user->last_login_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? 'Nunca acessou' }}{{ $user->last_login_ip ? ' · IP '.$user->last_login_ip : '' }}</x-knowledge-admin.fact>
        <x-knowledge-admin.fact label="Cadastrado por">{{ $user->creator?->name ?? 'Não informado' }}{{ $user->created_at ? ' em '.$user->created_at->timezone(config('app.timezone'))->format('d/m/Y') : '' }}</x-knowledge-admin.fact>
    </x-knowledge-admin.facts>

    @unless ($isCurrentUser)
        <x-knowledge-admin.ops-disclosure title="Mais ações" summary="Senha provisória, troca obrigatória, sessões e desativação. Cada ação é registrada no histórico.">
            <ul class="grid gap-2">
                <x-knowledge-admin.ops-row title="Nova senha provisória" support="A senha atual deixa de valer; a nova aparece uma única vez.">
                    <label class="flex items-center gap-2 text-xs text-slate-700"><input class="size-4 rounded border-slate-300 text-brand-700 focus:ring-brand-600" type="checkbox" wire:model="sendByEmail">Enviar por e-mail{{ $mailConfigured ? '' : ' (não configurado)' }}</label>
                    <x-knowledge-admin.confirm-modal action="issueTemporaryPassword" tone="danger" title="Gerar nova senha provisória?" :message="'A senha atual de '.$user->name.' deixa de valer e a pessoa será desconectada. A nova senha provisória aparece uma única vez, para você entregar por um canal seguro.'" confirm-label="Gerar nova senha" loading="Gerando…">
                        <x-knowledge-admin.button variant="secondary">Gerar</x-knowledge-admin.button>
                    </x-knowledge-admin.confirm-modal>
                </x-knowledge-admin.ops-row>
                @unless ($user->must_change_password)
                    <x-knowledge-admin.ops-row title="Exigir troca de senha" support="Entra com a senha atual, mas define uma nova no próximo acesso.">
                        <x-knowledge-admin.confirm-modal action="forcePasswordChange" title="Exigir troca de senha?" :message="$user->name.' continuará entrando com a senha atual, mas precisará definir uma nova senha no próximo acesso. As sessões abertas serão encerradas.'" confirm-label="Exigir troca" loading="Aplicando…">
                            <x-knowledge-admin.button variant="secondary">Exigir</x-knowledge-admin.button>
                        </x-knowledge-admin.confirm-modal>
                    </x-knowledge-admin.ops-row>
                @endunless
                <x-knowledge-admin.ops-row title="Encerrar sessões abertas" support="Desconecta a pessoa de todos os aparelhos; ela entra de novo com a senha atual.">
                    <x-knowledge-admin.confirm-modal action="terminateSessions" title="Encerrar as sessões?" :message="'Todas as sessões abertas de '.$user->name.' serão encerradas e o “Manter conectado” deixa de valer. A pessoa poderá entrar de novo com a senha atual.'" confirm-label="Encerrar sessões" loading="Encerrando…">
                        <x-knowledge-admin.button variant="secondary">Encerrar</x-knowledge-admin.button>
                    </x-knowledge-admin.confirm-modal>
                </x-knowledge-admin.ops-row>
                @if ($user->isActive())
                    <li class="grid gap-2 rounded-lg bg-rose-50 px-3 py-2.5 ring-1 ring-rose-200">
                        <p class="text-sm font-semibold text-slate-900">Desativar acesso</p>
                        <x-knowledge-admin.textarea label="Motivo" optional wire:model="reason" maxlength="255" hint="Fica no histórico do acesso (ex.: desligamento, pedido da pessoa)." rows="2" />
                        <div><x-knowledge-admin.confirm-modal action="toggleActive" tone="danger" title="Desativar acesso?" :message="'O acesso de '.$user->name.' será bloqueado e a pessoa será desconectada imediatamente. O histórico é mantido e o acesso pode ser reativado depois.'" confirm-label="Desativar acesso" loading="Desativando…">
                            <x-knowledge-admin.button variant="danger">Desativar acesso</x-knowledge-admin.button>
                        </x-knowledge-admin.confirm-modal></div>
                    </li>
                @else
                    <x-knowledge-admin.ops-row title="Reativar acesso" support="A pessoa volta a entrar com a senha atual.">
                        <x-knowledge-admin.confirm-modal action="toggleActive" title="Reativar acesso?" :message="'O acesso de '.$user->name.' será liberado e a pessoa poderá entrar no BULAI novamente com a senha atual.'" confirm-label="Reativar acesso" loading="Reativando…">
                            <x-knowledge-admin.button>Reativar</x-knowledge-admin.button>
                        </x-knowledge-admin.confirm-modal>
                    </x-knowledge-admin.ops-row>
                @endif
            </ul>
        </x-knowledge-admin.ops-disclosure>
    @endunless

    <x-knowledge-admin.card x-data="{ aba: 'atividade' }" class="grid gap-4">
        <div class="flex gap-1 border-b border-slate-200" role="tablist" aria-label="Detalhes do acesso">
            <x-knowledge-admin.button variant="quiet" role="tab" id="aba-atividade" aria-controls="painel-atividade" x-on:click="aba = 'atividade'" x-bind:aria-selected="aba === 'atividade'" x-bind:class="aba === 'atividade' ? 'border-b-2 border-brand-700 text-brand-800' : 'text-slate-600'">Atividade</x-knowledge-admin.button>
            <x-knowledge-admin.button variant="quiet" role="tab" id="aba-uso" aria-controls="painel-uso" x-on:click="aba = 'uso'" x-bind:aria-selected="aba === 'uso'" x-bind:class="aba === 'uso' ? 'border-b-2 border-brand-700 text-brand-800' : 'text-slate-600'">Uso do aplicativo</x-knowledge-admin.button>
        </div>

        <div id="painel-atividade" role="tabpanel" aria-labelledby="aba-atividade" x-show="aba === 'atividade'" class="grid gap-3">
            <p class="text-sm text-slate-600">Tudo o que aconteceu com este acesso, do mais recente para o mais antigo.</p>
            <ol class="grid gap-3">
                @forelse ($history as $event)
                    <li class="border-l-2 border-brand-200 pl-3" wire:key="ev-{{ $event->id }}">
                        <p class="text-sm font-medium text-slate-900">{{ \App\Models\UserAuditEvent::label($event->event) }}</p>
                        @if ($event->event === 'updated')
                            <p class="text-sm text-slate-600">@foreach ($event->details['changes'] ?? [] as $field => $change){{ match ($field) { 'name' => 'Nome', 'email' => 'E-mail', default => str($field)->headline() } }}: {{ $change['from'] ?? '—' }} → {{ $change['to'] ?? '—' }}@if (! $loop->last) · @endif @endforeach</p>
                        @elseif ($event->event === 'role_changed')
                            <p class="text-sm text-slate-600">{{ \App\UserRole::tryFrom($event->details['from'] ?? '')?->label() ?? '—' }} → {{ \App\UserRole::tryFrom($event->details['to'] ?? '')?->label() ?? '—' }}</p>
                        @elseif ($event->event === 'created' && isset($event->details['role']))
                            <p class="text-sm text-slate-600">Perfil: {{ \App\UserRole::tryFrom($event->details['role'])?->label() ?? $event->details['role'] }}</p>
                        @elseif ($event->event === 'password_changed' && ($event->details['via'] ?? null) === 'first_access')
                            <p class="text-sm text-slate-600">Ao substituir a senha provisória.</p>
                        @elseif (! empty($event->details['reason']))
                            <p class="text-sm text-slate-600">Motivo: {{ $event->details['reason'] }}</p>
                        @endif
                        <p class="text-sm text-slate-500">{{ $event->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}@if ($event->ip) · IP {{ $event->ip }}@endif @if ($event->actor) · {{ $event->actor->name }}@endif</p>
                    </li>
                @empty
                    <li class="text-sm text-slate-500">Nenhum evento registrado para este acesso.</li>
                @endforelse
            </ol>
            {{ $history->links() }}
        </div>

        <div id="painel-uso" role="tabpanel" aria-labelledby="aba-uso" x-cloak x-show="aba === 'uso'" class="grid gap-3">
            <p class="text-sm text-slate-600">Só contagens: o conteúdo de saúde (medicamentos, alergias, perguntas) é privado da pessoa e não aparece aqui.</p>
            <x-knowledge-admin.facts class="sm:grid-cols-3">
                <x-knowledge-admin.fact label="Consultas de interação">{{ $usage['consultations'] }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Perguntas">{{ $usage['questions'] }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Dados de saúde">{{ $usage['healthConsent'] ? 'Consentimento registrado' : 'Sem consentimento' }}</x-knowledge-admin.fact>
            </x-knowledge-admin.facts>
        </div>
    </x-knowledge-admin.card>

    @if ($temporaryPassword)
        <x-knowledge-admin.temporary-password-modal :password="$temporaryPassword" close="$refresh" />
    @endif
</x-knowledge-admin.shell>
