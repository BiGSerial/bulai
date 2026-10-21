<x-knowledge-admin.shell :title="$user->name" :subtitle="$user->email" :back="route('admin.users.index')" back-label="Usuários">
    <x-slot:actions>
        <x-knowledge-admin.badge :tone="$user->isActive() ? 'success' : 'neutral'">{{ $user->isActive() ? 'Ativo' : 'Inativo' }}</x-knowledge-admin.badge>
        @unless ($isCurrentUser)
            <x-knowledge-admin.confirm-modal action="issueTemporaryPassword" tone="danger" title="Gerar nova senha provisória?" :message="'A senha atual de '.$user->name.' deixa de valer e a pessoa será desconectada. A nova senha provisória aparece uma única vez, para você entregar por um canal seguro.'" confirm-label="Gerar nova senha" loading="Gerando…">
                <x-knowledge-admin.button variant="secondary">Gerar nova senha provisória</x-knowledge-admin.button>
            </x-knowledge-admin.confirm-modal>
            <x-knowledge-admin.confirm-modal action="toggleActive" :tone="$user->isActive() ? 'danger' : 'primary'" :title="$user->isActive() ? 'Desativar acesso?' : 'Reativar acesso?'" :message="$user->isActive() ? 'O acesso de '.$user->name.' será bloqueado e a pessoa será desconectada imediatamente. O histórico é mantido e o acesso pode ser reativado depois.' : 'O acesso de '.$user->name.' será liberado e a pessoa poderá entrar no BULAI novamente com a senha atual.'" :confirm-label="$user->isActive() ? 'Desativar acesso' : 'Reativar acesso'" :loading="$user->isActive() ? 'Desativando…' : 'Reativando…'">
                <x-knowledge-admin.button variant="secondary">{{ $user->isActive() ? 'Desativar' : 'Ativar' }}</x-knowledge-admin.button>
            </x-knowledge-admin.confirm-modal>
        @endunless
        <x-knowledge-admin.button :href="route('admin.users.edit', $user)">Editar</x-knowledge-admin.button>
    </x-slot:actions>

    @if (session('userFeedback'))<x-knowledge-admin.alert tone="success">{{ session('userFeedback') }}</x-knowledge-admin.alert>@endif
    @error('active')<x-knowledge-admin.alert tone="danger">{{ $message }}</x-knowledge-admin.alert>@enderror
    @error('temporaryPassword')<x-knowledge-admin.alert tone="danger">{{ $message }}</x-knowledge-admin.alert>@enderror
    @if ($isCurrentUser)<x-knowledge-admin.alert>Este é o seu acesso. Para trocar a sua senha, use <a class="font-medium underline" href="{{ route('account') }}">Minha conta</a>.</x-knowledge-admin.alert>@endif

    <x-knowledge-admin.section title="Detalhes">
        <x-knowledge-admin.facts>
            <x-knowledge-admin.fact label="Perfil">{{ $user->role->label() }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Situação">{{ $user->isActive() ? 'Ativo' : 'Inativo' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Troca de senha">{{ $user->must_change_password ? 'Pendente (usa senha provisória)' : 'Em dia' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Último acesso">{{ $user->last_login_at?->format('d/m/Y H:i') ?? 'Nunca acessou' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Cadastrado em">{{ $user->created_at?->format('d/m/Y H:i') ?? 'Não informado' }}</x-knowledge-admin.fact>
            <x-knowledge-admin.fact label="Cadastrado por">{{ $user->creator?->name ?? 'Não informado' }}</x-knowledge-admin.fact>
        </x-knowledge-admin.facts>
    </x-knowledge-admin.section>

    <x-knowledge-admin.section title="Histórico" description="Tudo o que aconteceu com este acesso, do mais recente para o mais antigo.">
        <div x-data="{ all: false }" class="grid gap-3">
            <div class="grid max-h-96 gap-3 overflow-y-auto pr-1">
                @forelse ($history as $event)
                    <div class="border-l-2 border-slate-200 pl-3" @if ($loop->index >= 6) x-show="all" x-cloak @endif>
                        <p class="text-sm font-medium text-slate-900">{{ match ($event->event) { 'created' => 'Usuário cadastrado', 'updated' => 'Dados alterados', 'role_changed' => 'Perfil alterado', 'deactivated' => 'Acesso desativado', 'reactivated' => 'Acesso reativado', 'temporary_password_issued' => 'Nova senha provisória gerada', 'password_changed' => 'Senha trocada pela própria pessoa', default => str($event->event)->headline() } }}</p>
                        @if ($event->event === 'updated')
                            <p class="text-sm text-slate-600">@foreach ($event->details['changes'] ?? [] as $field => $change){{ match ($field) { 'name' => 'Nome', 'email' => 'E-mail', default => str($field)->headline() } }}: {{ $change['from'] ?? '—' }} → {{ $change['to'] ?? '—' }}@if (! $loop->last) · @endif @endforeach</p>
                        @elseif ($event->event === 'role_changed')
                            <p class="text-sm text-slate-600">{{ \App\UserRole::tryFrom($event->details['from'] ?? '')?->label() ?? '—' }} → {{ \App\UserRole::tryFrom($event->details['to'] ?? '')?->label() ?? '—' }}</p>
                        @elseif ($event->event === 'created' && isset($event->details['role']))
                            <p class="text-sm text-slate-600">Perfil: {{ \App\UserRole::tryFrom($event->details['role'])?->label() ?? $event->details['role'] }}</p>
                        @elseif ($event->event === 'password_changed' && ($event->details['via'] ?? null) === 'first_access')
                            <p class="text-sm text-slate-600">Ao substituir a senha provisória.</p>
                        @endif
                        <p class="text-sm text-slate-500">{{ $event->created_at?->format('d/m/Y H:i') }}@if ($event->actor) · {{ $event->actor->name }}@endif</p>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Nenhum evento registrado para este acesso.</p>
                @endforelse
            </div>
            @if ($history->count() > 6)<div><x-knowledge-admin.button variant="quiet" x-on:click="all = ! all"><span x-text="all ? 'Mostrar menos' : 'Mostrar todos ({{ $history->count() }})'"></span></x-knowledge-admin.button></div>@endif
        </div>
    </x-knowledge-admin.section>

    @if ($temporaryPassword)
        <x-knowledge-admin.temporary-password-modal :password="$temporaryPassword" close="$refresh" />
    @endif
</x-knowledge-admin.shell>
