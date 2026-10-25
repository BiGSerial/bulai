<x-knowledge-admin.shell :title="$userId === null ? 'Novo usuário' : 'Editar usuário'" :subtitle="$userId === null ? 'O sistema gera uma senha provisória, que a pessoa troca no primeiro acesso.' : null" :back="route('admin.users.index')" back-label="Usuários">
    <form class="max-w-3xl" wire:submit="save">
        <x-knowledge-admin.section>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-knowledge-admin.input label="Nome" required wire:model="name" autocomplete="off" />
                <x-knowledge-admin.input type="email" label="E-mail" required wire:model="email" autocomplete="off" />
                <x-knowledge-admin.select label="Perfil" required wire:model="role" hint="Somente administradores acessam a área administrativa.">@foreach ($roleOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-knowledge-admin.select>
            </div>
            <div class="flex flex-wrap gap-3">
                <x-knowledge-admin.button type="submit" loading-target="save" loading="Salvando…">Salvar usuário</x-knowledge-admin.button>
                <x-knowledge-admin.button variant="secondary" :href="route('admin.users.index')">Cancelar</x-knowledge-admin.button>
            </div>
        </x-knowledge-admin.section>
    </form>

    @if ($temporaryPassword)
        <x-knowledge-admin.temporary-password-modal :password="$temporaryPassword" close="finishCreation" title="Usuário cadastrado" />
    @endif
</x-knowledge-admin.shell>
