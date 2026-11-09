<x-knowledge-admin.shell :title="$user->name" :back="route('admin.users.index')" back-label="Usuários">
    <x-slot:actions>
        <x-knowledge-admin.button :href="route('admin.users.edit', $user)">Editar</x-knowledge-admin.button>
    </x-slot:actions>

    @php
        [$statusLabel, $statusTone] = match (true) {
            $user->isAwaitingApproval() => ['Aguardando aprovação', 'warning'],
            $user->isRejected() => ['Cadastro recusado', 'danger'],
            ! $user->isActive() => ['Inativo', 'neutral'],
            $user->must_change_password => ['Troca de senha pendente', 'warning'],
            default => ['Ativo', 'success'],
        };
        $reviewedAt = $user->approval_reviewed_at?->timezone(config('app.timezone'))->format('d/m/Y H:i');
        $approvalFact = match (true) {
            $user->isAwaitingApproval() => 'Aguardando aprovação',
            $user->isRejected() => 'Recusado'.($user->approvalReviewer ? ' por '.$user->approvalReviewer->name : '').($reviewedAt ? ' em '.$reviewedAt : ''),
            $reviewedAt !== null => 'Aprovado'.($user->approvalReviewer ? ' por '.$user->approvalReviewer->name : '').' em '.$reviewedAt,
            default => 'Aprovado (anterior à aprovação de cadastros)',
        };
    @endphp
    <x-knowledge-admin.page-header :badge="$statusLabel" :badge-tone="$statusTone" :meta="[$user->email, $user->role->label(), $user->last_login_at ? 'Último acesso em '.$user->last_login_at->timezone(config('app.timezone'))->format('d/m/Y H:i') : 'Nunca acessou']" />

    @if (session('userFeedback'))<x-knowledge-admin.alert tone="success">{{ session('userFeedback') }}</x-knowledge-admin.alert>@endif
    @if ($feedback)<x-knowledge-admin.alert tone="success">{{ $feedback }}</x-knowledge-admin.alert>@endif
    @error('active')<x-knowledge-admin.alert tone="danger">{{ $message }}</x-knowledge-admin.alert>@enderror
    @error('temporaryPassword')<x-knowledge-admin.alert tone="danger">{{ $message }}</x-knowledge-admin.alert>@enderror
    @error('mustChangePassword')<x-knowledge-admin.alert tone="danger">{{ $message }}</x-knowledge-admin.alert>@enderror
    @error('approval')<x-knowledge-admin.alert tone="danger">{{ $message }}</x-knowledge-admin.alert>@enderror
    @if (($user->isAwaitingApproval() || $user->isRejected()) && ! $isCurrentUser)
        <div class="grid gap-3 rounded-lg bg-amber-50 px-3 py-3 ring-1 ring-amber-200" data-approval-panel>
            <p class="text-sm text-amber-900">
                @if ($user->isAwaitingApproval())
                    <span class="font-semibold">Cadastro aguardando aprovação.</span> Feito pela própria pessoa no site em {{ $user->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}{{ $user->terms_accepted_at ? ', com aceite do aviso de privacidade' : '' }}. Ela só entra depois de aprovada.
                @else
                    <span class="font-semibold">Cadastro recusado.</span> A pessoa continua sem acesso. Se foi engano, aprove o cadastro abaixo.
                @endif
            </p>
            <div class="flex flex-wrap items-end gap-3">
                <x-knowledge-admin.confirm-modal action="approve" title="Aprovar cadastro?" :message="$user->name.' ('.$user->email.') poderá entrar no BULAI com a senha que criou no cadastro e receberá um e-mail avisando que o acesso foi liberado.'" confirm-label="Aprovar cadastro" loading="Aprovando…">
                    <x-knowledge-admin.button>Aprovar cadastro</x-knowledge-admin.button>
                </x-knowledge-admin.confirm-modal>
                @if ($user->isAwaitingApproval())
                    <x-knowledge-admin.textarea label="Recusar: motivo" optional wire:model="rejectionReason" maxlength="255" hint="Fica só no histórico; a pessoa não recebe o motivo." rows="1" wrapper-class="min-w-0 flex-1" />
                    <div class="shrink-0 sm:pb-6"><x-knowledge-admin.confirm-modal action="reject" tone="danger" title="Recusar cadastro?" :message="$user->name.' continuará sem acesso. A conta e o histórico são mantidos e o cadastro pode ser aprovado depois.'" confirm-label="Recusar cadastro" loading="Recusando…">
                        <x-knowledge-admin.button variant="danger">Recusar cadastro</x-knowledge-admin.button>
                    </x-knowledge-admin.confirm-modal></div>
                @endif
            </div>
        </div>
    @endif
    @if ($isCurrentUser)<x-knowledge-admin.callout title="Este é o seu acesso.">Para trocar a sua senha ou encerrar outras sessões, use <a class="font-medium underline" href="{{ route('account') }}">Minha conta</a>.</x-knowledge-admin.callout>@endif

    <x-knowledge-admin.card class="grid gap-4 !p-0">
        <x-knowledge-admin.tabs class="px-2" :tabs="[['key' => 'dados', 'label' => 'Dados'], ['key' => 'acesso', 'label' => 'Acesso'], ['key' => 'historico', 'label' => 'Histórico', 'count' => $history->total()]]" :active="$tab" model="tab" />

        {{-- Os três painéis são renderizados; só o da aba ativa fica visível. --}}
        <div id="painel-dados" role="tabpanel" aria-labelledby="aba-dados" class="grid gap-5 px-4 pb-4 sm:px-5 sm:pb-5" @if ($tab !== 'dados') hidden @endif>
            <x-knowledge-admin.facts class="sm:grid-cols-3">
                <x-knowledge-admin.fact label="Situação">{{ $user->isActive() ? 'Ativo' : 'Inativo' }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Aprovação do cadastro">{{ $approvalFact }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Troca de senha">{{ $user->must_change_password ? 'Pendente (usa senha provisória)' : 'Em dia' }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Último acesso">{{ $user->last_login_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') ?? 'Nunca acessou' }}{{ $user->last_login_ip ? ' · IP '.$user->last_login_ip : '' }}</x-knowledge-admin.fact>
                <x-knowledge-admin.fact label="Cadastrado por">{{ $user->creator?->name ?? ($user->registeredThemselves() ? 'A própria pessoa, pelo site' : 'Não informado') }}{{ $user->created_at ? ' em '.$user->created_at->timezone(config('app.timezone'))->format('d/m/Y') : '' }}</x-knowledge-admin.fact>
            </x-knowledge-admin.facts>
            <div class="grid gap-2">
                <h2 class="text-sm font-semibold text-slate-900">Uso do aplicativo</h2>
                <p class="text-xs text-slate-500">Só contagens: o conteúdo de saúde (medicamentos, alergias, perguntas) é privado da pessoa e não aparece aqui.</p>
                <x-knowledge-admin.facts class="sm:grid-cols-3">
                    <x-knowledge-admin.fact label="Consultas de interação">{{ $usage['consultations'] }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Perguntas">{{ $usage['questions'] }}</x-knowledge-admin.fact>
                    <x-knowledge-admin.fact label="Dados de saúde">{{ $usage['healthConsent'] ? 'Consentimento registrado' : 'Sem consentimento' }}</x-knowledge-admin.fact>
                </x-knowledge-admin.facts>
            </div>
        </div>

        <div id="painel-acesso" role="tabpanel" aria-labelledby="aba-acesso" class="grid gap-3 px-4 pb-4 sm:px-5 sm:pb-5" @if ($tab !== 'acesso') hidden @endif>
            @if ($isCurrentUser)
                <p class="text-sm text-slate-600">As ações de acesso sobre a sua própria conta ficam em <a class="font-medium text-brand-700 underline" href="{{ route('account') }}">Minha conta</a>.</p>
            @else
                <p class="text-xs text-slate-500">Cada ação é registrada no histórico com data, IP e quem executou.</p>
                <x-knowledge-admin.data-table :columns="[['label' => 'Ação'], ['label' => 'O que acontece'], ['label' => '', 'align' => 'right']]" dense>
                    <tr>
                        <td>Nova senha provisória</td>
                        <td class="!whitespace-normal text-slate-600">A senha atual deixa de valer; a nova aparece uma única vez. <label class="ml-2 inline-flex items-center gap-1.5 text-xs text-slate-700"><input class="size-4 rounded border-slate-300 text-brand-700 focus:ring-brand-600" type="checkbox" wire:model="sendByEmail">Enviar por e-mail{{ $mailConfigured ? '' : ' (não configurado)' }}</label></td>
                        <td><x-knowledge-admin.row-actions>
                            <x-knowledge-admin.confirm-modal action="issueTemporaryPassword" tone="danger" title="Gerar nova senha provisória?" :message="'A senha atual de '.$user->name.' deixa de valer e a pessoa será desconectada. A nova senha provisória aparece uma única vez, para você entregar por um canal seguro.'" confirm-label="Gerar nova senha" loading="Gerando…">
                                <x-knowledge-admin.button variant="secondary" size="sm">Gerar</x-knowledge-admin.button>
                            </x-knowledge-admin.confirm-modal>
                        </x-knowledge-admin.row-actions></td>
                    </tr>
                    @unless ($user->must_change_password)
                        <tr>
                            <td>Exigir troca de senha</td>
                            <td class="!whitespace-normal text-slate-600">Entra com a senha atual, mas define uma nova no próximo acesso.</td>
                            <td><x-knowledge-admin.row-actions>
                                <x-knowledge-admin.confirm-modal action="forcePasswordChange" title="Exigir troca de senha?" :message="$user->name.' continuará entrando com a senha atual, mas precisará definir uma nova senha no próximo acesso. As sessões abertas serão encerradas.'" confirm-label="Exigir troca" loading="Aplicando…">
                                    <x-knowledge-admin.button variant="secondary" size="sm">Exigir</x-knowledge-admin.button>
                                </x-knowledge-admin.confirm-modal>
                            </x-knowledge-admin.row-actions></td>
                        </tr>
                    @endunless
                    <tr>
                        <td>Encerrar sessões abertas</td>
                        <td class="!whitespace-normal text-slate-600">Desconecta a pessoa de todos os aparelhos; ela entra de novo com a senha atual.</td>
                        <td><x-knowledge-admin.row-actions>
                            <x-knowledge-admin.confirm-modal action="terminateSessions" title="Encerrar as sessões?" :message="'Todas as sessões abertas de '.$user->name.' serão encerradas e o “Manter conectado” deixa de valer. A pessoa poderá entrar de novo com a senha atual.'" confirm-label="Encerrar sessões" loading="Encerrando…">
                                <x-knowledge-admin.button variant="secondary" size="sm">Encerrar</x-knowledge-admin.button>
                            </x-knowledge-admin.confirm-modal>
                        </x-knowledge-admin.row-actions></td>
                    </tr>
                    @unless ($user->isActive())
                        <tr>
                            <td>Reativar acesso</td>
                            <td class="!whitespace-normal text-slate-600">A pessoa volta a entrar com a senha atual.</td>
                            <td><x-knowledge-admin.row-actions>
                                <x-knowledge-admin.confirm-modal action="toggleActive" title="Reativar acesso?" :message="'O acesso de '.$user->name.' será liberado e a pessoa poderá entrar no BULAI novamente com a senha atual.'" confirm-label="Reativar acesso" loading="Reativando…">
                                    <x-knowledge-admin.button size="sm">Reativar</x-knowledge-admin.button>
                                </x-knowledge-admin.confirm-modal>
                            </x-knowledge-admin.row-actions></td>
                        </tr>
                    @endunless
                </x-knowledge-admin.data-table>
                @if ($user->isActive())
                    <div class="grid gap-2 rounded-lg bg-rose-50 px-3 py-2.5 ring-1 ring-rose-200 sm:flex sm:items-end sm:gap-3">
                        <x-knowledge-admin.textarea label="Desativar acesso: motivo" optional wire:model="reason" maxlength="255" hint="Fica no histórico do acesso (ex.: desligamento, pedido da pessoa)." rows="1" wrapper-class="min-w-0 flex-1" />
                        <div class="shrink-0 sm:pb-6"><x-knowledge-admin.confirm-modal action="toggleActive" tone="danger" title="Desativar acesso?" :message="'O acesso de '.$user->name.' será bloqueado e a pessoa será desconectada imediatamente. O histórico é mantido e o acesso pode ser reativado depois.'" confirm-label="Desativar acesso" loading="Desativando…">
                            <x-knowledge-admin.button variant="danger">Desativar acesso</x-knowledge-admin.button>
                        </x-knowledge-admin.confirm-modal></div>
                    </div>
                @endif
            @endif
        </div>

        <div id="painel-historico" role="tabpanel" aria-labelledby="aba-historico" class="grid gap-3 px-4 pb-4 sm:px-5 sm:pb-5" @if ($tab !== 'historico') hidden @endif>
            <p class="text-xs text-slate-500">Tudo o que aconteceu com este acesso, do mais recente para o mais antigo.</p>
            <x-knowledge-admin.data-table :columns="[['label' => 'Evento'], ['label' => 'Detalhe'], ['label' => 'Quando'], ['label' => 'Por quem', 'align' => 'right']]" dense>
                @foreach ($history as $event)
                    <tr wire:key="ev-{{ $event->id }}">
                        <td>{{ \App\Models\UserAuditEvent::label($event->event) }}</td>
                        <td class="!whitespace-normal text-slate-600">
                            @if ($event->event === 'updated')
                                @foreach ($event->details['changes'] ?? [] as $field => $change){{ match ($field) { 'name' => 'Nome', 'email' => 'E-mail', default => str($field)->headline() } }}: {{ $change['from'] ?? '—' }} → {{ $change['to'] ?? '—' }}@if (! $loop->last) · @endif @endforeach
                            @elseif ($event->event === 'role_changed')
                                {{ \App\UserRole::tryFrom($event->details['from'] ?? '')?->label() ?? '—' }} → {{ \App\UserRole::tryFrom($event->details['to'] ?? '')?->label() ?? '—' }}
                            @elseif ($event->event === 'created' && isset($event->details['role']))
                                Perfil: {{ \App\UserRole::tryFrom($event->details['role'])?->label() ?? $event->details['role'] }}
                            @elseif ($event->event === 'password_changed' && ($event->details['via'] ?? null) === 'first_access')
                                Ao substituir a senha provisória.
                            @elseif (! empty($event->details['reason']))
                                Motivo: {{ $event->details['reason'] }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="tabular text-slate-600">{{ $event->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}@if ($event->ip) <span class="text-xs text-slate-400">· IP {{ $event->ip }}</span>@endif</td>
                        <td class="text-slate-600">{{ $event->actor?->name ?? '—' }}</td>
                    </tr>
                @endforeach
                @if ($history->isEmpty())
                    <x-slot:empty>Nenhum evento registrado para este acesso.</x-slot:empty>
                @endif
            </x-knowledge-admin.data-table>
            {{ $history->links() }}
        </div>
    </x-knowledge-admin.card>

    @if ($temporaryPassword)
        <x-knowledge-admin.temporary-password-modal :password="$temporaryPassword" close="$refresh" :warning="$errors->first('temporaryPassword')" />
    @endif
</x-knowledge-admin.shell>
