<x-knowledge-admin.shell :title="$userId === null ? 'Novo usuário' : 'Editar usuário'" :subtitle="$userId === null ? 'O sistema gera uma senha provisória, que a pessoa troca no primeiro acesso.' : 'Trocar o perfil encerra as sessões abertas da pessoa.'" :back="$userId === null ? route('admin.users.index') : route('admin.users.show', $userId)" :back-label="$userId === null ? 'Usuários' : 'Página do usuário'">
    <form class="max-w-xl" wire:submit="save">
        <x-knowledge-admin.section title="Dados de acesso">
            <x-knowledge-admin.input label="Nome" required wire:model="name" autocomplete="off" />
            <x-knowledge-admin.input type="email" label="E-mail" required wire:model="email" autocomplete="off" inputmode="email" autocapitalize="none" spellcheck="false" />
            <x-knowledge-admin.select label="Perfil" required wire:model="role" hint="Administradores acessam a administração; usuários usam o aplicativo do paciente.">@foreach ($roleOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-knowledge-admin.select>
            @if ($userId === null)
                <label class="flex items-start gap-2 text-sm text-slate-700"><input class="mt-0.5 size-4 rounded border-slate-300 text-brand-700 focus:ring-brand-600" type="checkbox" wire:model="sendByEmail"><span>Enviar a senha provisória por e-mail<span class="block text-slate-500">{{ $mailConfigured ? 'A senha também aparece uma vez na tela, para entrega por outro canal.' : 'O envio de e-mail não está configurado neste ambiente; a senha será registrada no log.' }}</span></span></label>
            @endif
            <div class="flex flex-wrap gap-3">
                <x-knowledge-admin.button type="submit" loading-target="save" loading="Salvando…">{{ $userId === null ? 'Cadastrar usuário' : 'Salvar alterações' }}</x-knowledge-admin.button>
                <x-knowledge-admin.button variant="quiet" :href="$userId === null ? route('admin.users.index') : route('admin.users.show', $userId)">Cancelar</x-knowledge-admin.button>
            </div>
        </x-knowledge-admin.section>
    </form>

    @if ($temporaryPassword)
        <x-knowledge-admin.temporary-password-modal :password="$temporaryPassword" close="finishCreation" title="Usuário cadastrado" />
    @endif
</x-knowledge-admin.shell>
