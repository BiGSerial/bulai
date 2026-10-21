<?php

namespace App\Livewire\Admin\Users;

use App\Models\User;
use App\Services\Users\UserAccountService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    #[Locked]
    public int $userId;

    /**
     * Senha provisória recém-gerada. Não é propriedade pública: não vai para o snapshot
     * do Livewire nem para a sessão, e some na requisição seguinte (exibida uma única vez).
     */
    protected ?string $temporaryPassword = null;

    /**
     * Defesa em profundidade: além do middleware da rota (reaplicado nas ações pelo Livewire),
     * toda requisição ao componente confere o perfil de administrador.
     */
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function mount(User $user): void
    {
        $this->userId = $user->id;
    }

    public function toggleActive(UserAccountService $service): void
    {
        $user = User::query()->findOrFail($this->userId);
        $service->setActive($user, ! $user->isActive(), auth()->user());
    }

    public function issueTemporaryPassword(UserAccountService $service): void
    {
        $this->temporaryPassword = $service->issueTemporaryPassword(User::query()->findOrFail($this->userId), auth()->user());
    }

    public function render(): View
    {
        $user = User::query()->with('creator:id,name')->findOrFail($this->userId);

        return view('livewire.admin.users.show', [
            'user' => $user,
            'isCurrentUser' => $user->is(auth()->user()),
            'history' => $user->auditEvents()->with('actor:id,name')->orderByDesc('created_at')->orderByDesc('id')->get(),
            'temporaryPassword' => $this->temporaryPassword,
        ])->title($user->name.' · BULAI');
    }
}
