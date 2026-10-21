<x-knowledge-admin.shell title="Usuários" subtitle="Quem acessa o BULAI e com qual perfil.">
    <x-slot:actions><x-knowledge-admin.button :href="route('admin.users.create')">Novo usuário</x-knowledge-admin.button></x-slot:actions>
    @error('active')<x-knowledge-admin.alert tone="danger">{{ $message }}</x-knowledge-admin.alert>@enderror
    <div class="grid gap-3 sm:grid-cols-[minmax(0,28rem)_12rem_12rem]">
        <x-knowledge-admin.search label="Buscar" wire:model.live.debounce.300ms="search" placeholder="Nome ou e-mail" />
        <x-knowledge-admin.select label="Situação" wire:model.live="status"><option value="all">Todos</option><option value="active">Ativos</option><option value="inactive">Inativos</option></x-knowledge-admin.select>
        <x-knowledge-admin.select label="Perfil" wire:model.live="role"><option value="all">Todos</option>@foreach ($roleOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-knowledge-admin.select>
    </div>
    <section class="grid gap-3" wire:loading.class="opacity-60" wire:target="search,status,role">
        @forelse ($users as $user)
            <x-knowledge-admin.card class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" wire:key="user-{{ $user->id }}">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2"><h2 class="text-base font-semibold text-slate-900">{{ $user->name }}</h2><x-knowledge-admin.badge :tone="$user->isAdmin() ? 'info' : 'neutral'">{{ $user->role->label() }}</x-knowledge-admin.badge><x-knowledge-admin.badge :tone="$user->isActive() ? 'success' : 'neutral'">{{ $user->isActive() ? 'Ativo' : 'Inativo' }}</x-knowledge-admin.badge>@if ($user->must_change_password)<x-knowledge-admin.badge tone="warning">Troca de senha pendente</x-knowledge-admin.badge>@endif</div>
                    <p class="mt-1 break-words text-sm text-slate-500">{{ $user->email }} · {{ $user->last_login_at ? 'Último acesso em '.$user->last_login_at->format('d/m/Y H:i') : 'Nunca acessou' }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-knowledge-admin.button variant="secondary" :href="route('admin.users.show', $user)">Ver</x-knowledge-admin.button>
                    <x-knowledge-admin.button variant="secondary" :href="route('admin.users.edit', $user)">Editar</x-knowledge-admin.button>
                    @unless ($user->is(auth()->user()))
                        <x-knowledge-admin.confirm-modal action="toggleActive({{ $user->id }})" :tone="$user->isActive() ? 'danger' : 'primary'" :title="$user->isActive() ? 'Desativar acesso?' : 'Reativar acesso?'" :message="$user->isActive() ? 'O acesso de '.$user->name.' será bloqueado e a pessoa será desconectada imediatamente. O histórico é mantido e o acesso pode ser reativado depois.' : 'O acesso de '.$user->name.' será liberado e a pessoa poderá entrar no BULAI novamente com a senha atual.'" :confirm-label="$user->isActive() ? 'Desativar acesso' : 'Reativar acesso'" :loading="$user->isActive() ? 'Desativando…' : 'Reativando…'">
                            <x-knowledge-admin.button variant="quiet">{{ $user->isActive() ? 'Desativar' : 'Ativar' }}</x-knowledge-admin.button>
                        </x-knowledge-admin.confirm-modal>
                    @endunless
                </div>
            </x-knowledge-admin.card>
        @empty
            <x-knowledge-admin.empty-state>Nenhum usuário encontrado.</x-knowledge-admin.empty-state>
        @endforelse
    </section>
    {{ $users->links() }}
</x-knowledge-admin.shell>
