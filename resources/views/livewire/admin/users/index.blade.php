<x-knowledge-admin.shell title="Usuários" subtitle="Quem acessa o BULAI e com qual perfil.">
    <x-slot:actions><x-knowledge-admin.button :href="route('admin.users.create')">Novo usuário</x-knowledge-admin.button></x-slot:actions>
    @error('active')<x-knowledge-admin.alert tone="danger">{{ $message }}</x-knowledge-admin.alert>@enderror
    @if ($totals['admins'] <= 1)
        <x-knowledge-admin.alert tone="warning">Só um administrador ativo: cadastre outro para não ficar sem acesso à administração.</x-knowledge-admin.alert>
    @endif

    <x-knowledge-admin.ops-filters :count="$users->total().' '.($users->total() === 1 ? 'usuário encontrado' : 'usuários encontrados').(trim($search) !== '' ? ' para “'.$search.'”' : '').' · '.$totals['active'].' ativos · '.$totals['pending'].' com troca de senha pendente'">
        <x-knowledge-admin.search label="Buscar" wire:model.live.debounce.300ms="search" placeholder="Nome ou e-mail" wrapper-class="w-full sm:max-w-xs" />
        <x-knowledge-admin.select label="Situação" wire:model.live="status" wrapper-class="w-full sm:w-44"><option value="all">Todas</option><option value="active">Ativos</option><option value="inactive">Inativos</option><option value="pending">Troca de senha pendente</option></x-knowledge-admin.select>
        <x-knowledge-admin.select label="Perfil" wire:model.live="role" wrapper-class="w-full sm:w-40"><option value="all">Todos</option>@foreach ($roleOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-knowledge-admin.select>
        <x-knowledge-admin.select label="Ordenar por" wire:model.live="sort" wrapper-class="w-full sm:w-48"><option value="name">Nome</option><option value="recent_login">Acesso mais recente</option><option value="newest">Cadastro mais recente</option></x-knowledge-admin.select>
    </x-knowledge-admin.ops-filters>

    @if ($users->isEmpty())
        <x-knowledge-admin.empty-state>Nenhum usuário encontrado com estes filtros.</x-knowledge-admin.empty-state>
    @else
        <ul class="grid gap-2" wire:loading.class="opacity-60" wire:target="search,status,role,sort">
            @foreach ($users as $user)
                <x-knowledge-admin.ops-row :title="$user->name.($user->is(auth()->user()) ? ' (você)' : '')" :href="route('admin.users.show', $user)" :support="$user->email.' · '.$user->role->label().' · '.($user->last_login_at ? 'Último acesso em '.$user->last_login_at->timezone(config('app.timezone'))->format('d/m/Y H:i') : 'Nunca acessou')" wire:key="user-{{ $user->id }}">
                    @if (! $user->isActive())
                        <x-knowledge-admin.badge>Inativo</x-knowledge-admin.badge>
                    @elseif ($user->must_change_password)
                        <x-knowledge-admin.badge tone="warning">Troca de senha pendente</x-knowledge-admin.badge>
                    @else
                        <x-knowledge-admin.badge tone="success">Ativo</x-knowledge-admin.badge>
                    @endif
                    <x-knowledge-admin.button variant="secondary" :href="route('admin.users.show', $user)">Abrir</x-knowledge-admin.button>
                    @unless ($user->is(auth()->user()))
                        <x-knowledge-admin.confirm-modal action="toggleActive({{ $user->id }})" :tone="$user->isActive() ? 'danger' : 'primary'" :title="$user->isActive() ? 'Desativar acesso?' : 'Reativar acesso?'" :message="$user->isActive() ? 'O acesso de '.$user->name.' será bloqueado e a pessoa será desconectada imediatamente. O histórico é mantido e o acesso pode ser reativado depois. Para registrar um motivo, desative pela página do usuário.' : 'O acesso de '.$user->name.' será liberado e a pessoa poderá entrar no BULAI novamente com a senha atual.'" :confirm-label="$user->isActive() ? 'Desativar acesso' : 'Reativar acesso'" :loading="$user->isActive() ? 'Desativando…' : 'Reativando…'">
                            <x-knowledge-admin.button variant="quiet">{{ $user->isActive() ? 'Desativar' : 'Ativar' }}</x-knowledge-admin.button>
                        </x-knowledge-admin.confirm-modal>
                    @endunless
                </x-knowledge-admin.ops-row>
            @endforeach
        </ul>
    @endif
    {{ $users->links() }}
</x-knowledge-admin.shell>
