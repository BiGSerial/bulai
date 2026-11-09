<x-knowledge-admin.shell title="Usuários" subtitle="Quem acessa o BULAI e com qual perfil. Clique no cabeçalho de uma coluna para ordenar.">
    <x-slot:actions><x-knowledge-admin.button :href="route('admin.users.create')">Novo usuário</x-knowledge-admin.button></x-slot:actions>
    @error('active')<x-knowledge-admin.alert tone="danger">{{ $message }}</x-knowledge-admin.alert>@enderror
    @error('approval')<x-knowledge-admin.alert tone="danger">{{ $message }}</x-knowledge-admin.alert>@enderror
    @if ($feedback)<x-knowledge-admin.alert tone="success">{{ $feedback }}</x-knowledge-admin.alert>@endif
    @if ($totals['awaitingApproval'] > 0 && $status !== 'awaiting_approval')
        <div class="flex flex-wrap items-center gap-2" data-awaiting-approval>
            <x-knowledge-admin.callout tone="warning" class="min-w-0 flex-1" :title="$totals['awaitingApproval'] === 1 ? '1 cadastro aguardando aprovação.' : $totals['awaitingApproval'].' cadastros aguardando aprovação.'">Feitos pelo site; a pessoa só entra depois que um administrador aprova.</x-knowledge-admin.callout>
            <x-knowledge-admin.button variant="secondary" size="sm" wire:click="showAwaitingApproval">Ver pendentes</x-knowledge-admin.button>
        </div>
    @endif
    @if ($totals['admins'] <= 1)
        <x-knowledge-admin.callout tone="warning" title="Só um administrador ativo.">Cadastre outro para não ficar sem acesso à administração.</x-knowledge-admin.callout>
    @endif

    <x-knowledge-admin.toolbar :count="$users->total().' '.($users->total() === 1 ? 'usuário encontrado' : 'usuários encontrados').(trim($search) !== '' ? ' para “'.$search.'”' : '').' · '.$totals['active'].' ativos · '.$totals['pending'].' com troca de senha pendente'.($totals['awaitingApproval'] > 0 ? ' · '.$totals['awaitingApproval'].' aguardando aprovação' : '')">
        <x-knowledge-admin.search label="Buscar" compact wire:model.live.debounce.300ms="search" placeholder="Nome ou e-mail" />
        <x-knowledge-admin.select label="Situação" compact wire:model.live="status" wrapper-class="w-full sm:w-44"><option value="all">Todas as situações</option><option value="active">Ativos</option><option value="inactive">Inativos</option><option value="pending">Troca de senha pendente</option><option value="awaiting_approval">Aguardando aprovação</option><option value="rejected">Cadastro recusado</option></x-knowledge-admin.select>
        <x-knowledge-admin.select label="Perfil" compact wire:model.live="role" wrapper-class="w-full sm:w-40"><option value="all">Todos os perfis</option>@foreach ($roleOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-knowledge-admin.select>
        @if (trim($search) !== '' || $status !== 'all' || $role !== 'all')
            <x-slot:actions><x-knowledge-admin.button variant="quiet" size="sm" wire:click="clearFilters">Limpar</x-knowledge-admin.button></x-slot:actions>
        @endif
    </x-knowledge-admin.toolbar>

    <div wire:loading.class="opacity-60" wire:target="search,status,role,sort,direction,sortBy,clearFilters">
        <x-knowledge-admin.data-table :columns="[['label' => 'Nome', 'sort' => 'name'], ['label' => 'E-mail', 'sort' => 'email'], ['label' => 'Perfil', 'sort' => 'role'], ['label' => 'Situação'], ['label' => 'Último acesso', 'sort' => 'recent_login'], ['label' => 'Cadastro', 'sort' => 'newest'], ['label' => 'Ações', 'align' => 'right']]" :sort="$sort" :direction="$effectiveDirection">
            @foreach ($users as $user)
                <tr wire:key="user-{{ $user->id }}">
                    <td><a class="rounded hover:text-brand-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600" href="{{ route('admin.users.show', $user) }}" wire:navigate>{{ $user->name }}</a>@if ($user->is(auth()->user())) <span class="text-xs font-normal text-slate-500">(você)</span>@endif</td>
                    <td class="text-slate-600">{{ $user->email }}</td>
                    <td>{{ $user->role->label() }}</td>
                    <td>
                        @if ($user->isAwaitingApproval())
                            <x-knowledge-admin.badge tone="warning">Aguardando aprovação</x-knowledge-admin.badge>
                        @elseif ($user->isRejected())
                            <x-knowledge-admin.badge tone="danger">Cadastro recusado</x-knowledge-admin.badge>
                        @elseif (! $user->isActive())
                            <x-knowledge-admin.badge>Inativo</x-knowledge-admin.badge>
                        @elseif ($user->must_change_password)
                            <x-knowledge-admin.badge tone="warning">Troca de senha pendente</x-knowledge-admin.badge>
                        @else
                            <x-knowledge-admin.badge tone="success">Ativo</x-knowledge-admin.badge>
                        @endif
                    </td>
                    <td class="tabular text-slate-600" title="{{ $user->last_login_at ? 'Último acesso em '.$user->last_login_at->timezone(config('app.timezone'))->format('d/m/Y H:i') : 'Nunca acessou' }}">{{ $user->last_login_at ? 'Último acesso em '.$user->last_login_at->timezone(config('app.timezone'))->format('d/m/Y H:i') : 'Nunca acessou' }}</td>
                    <td class="tabular text-slate-600">{{ $user->created_at?->timezone(config('app.timezone'))->format('d/m/Y') ?? '—' }}</td>
                    <td>
                        <x-knowledge-admin.row-actions>
                            <x-knowledge-admin.button variant="quiet" size="sm" :href="route('admin.users.show', $user)">Abrir</x-knowledge-admin.button>
                            @if ($user->isAwaitingApproval())
                                <x-knowledge-admin.confirm-modal action="approve({{ $user->id }})" title="Aprovar cadastro?" :message="$user->name.' ('.$user->email.') poderá entrar no BULAI com a senha que criou no cadastro e receberá um e-mail avisando que o acesso foi liberado.'" confirm-label="Aprovar cadastro" loading="Aprovando…">
                                    <x-knowledge-admin.button size="sm">Aprovar</x-knowledge-admin.button>
                                </x-knowledge-admin.confirm-modal>
                                <x-knowledge-admin.confirm-modal action="reject({{ $user->id }})" tone="danger" title="Recusar cadastro?" :message="$user->name.' continuará sem acesso. A conta e o histórico são mantidos e o cadastro pode ser aprovado depois. Para registrar um motivo, recuse pela página do usuário.'" confirm-label="Recusar cadastro" loading="Recusando…">
                                    <x-knowledge-admin.button variant="quiet" size="sm">Recusar</x-knowledge-admin.button>
                                </x-knowledge-admin.confirm-modal>
                            @elseif (! $user->is(auth()->user()))
                                <x-knowledge-admin.confirm-modal action="toggleActive({{ $user->id }})" :tone="$user->isActive() ? 'danger' : 'primary'" :title="$user->isActive() ? 'Desativar acesso?' : 'Reativar acesso?'" :message="$user->isActive() ? 'O acesso de '.$user->name.' será bloqueado e a pessoa será desconectada imediatamente. O histórico é mantido e o acesso pode ser reativado depois. Para registrar um motivo, desative pela página do usuário.' : 'O acesso de '.$user->name.' será liberado e a pessoa poderá entrar no BULAI novamente com a senha atual.'" :confirm-label="$user->isActive() ? 'Desativar acesso' : 'Reativar acesso'" :loading="$user->isActive() ? 'Desativando…' : 'Reativando…'">
                                    <x-knowledge-admin.button variant="quiet" size="sm">{{ $user->isActive() ? 'Desativar' : 'Ativar' }}</x-knowledge-admin.button>
                                </x-knowledge-admin.confirm-modal>
                            @endif
                        </x-knowledge-admin.row-actions>
                    </td>
                </tr>
            @endforeach
            @if ($users->isEmpty())
                <x-slot:empty>Nenhum usuário encontrado com estes filtros.</x-slot:empty>
            @endif
        </x-knowledge-admin.data-table>
    </div>
    {{ $users->links() }}
</x-knowledge-admin.shell>
