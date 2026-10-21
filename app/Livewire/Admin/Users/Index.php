<?php

namespace App\Livewire\Admin\Users;

use App\Models\User;
use App\Services\Users\UserAccountService;
use App\UserRole;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public string $status = 'all';

    public string $role = 'all';

    /**
     * Defesa em profundidade: além do middleware da rota (reaplicado nas ações pelo Livewire),
     * toda requisição ao componente confere o perfil de administrador.
     */
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    public function toggleActive(int $userId, UserAccountService $service): void
    {
        $user = User::query()->findOrFail($userId);
        $service->setActive($user, ! $user->isActive(), auth()->user());
    }

    public function render(): View
    {
        $search = trim($this->search);

        return view('livewire.admin.users.index', [
            'users' => User::query()
                ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                    $query->whereLike('name', "%{$search}%")
                        ->orWhereLike('email', "%{$search}%");
                }))
                ->when($this->status === 'active', fn (Builder $query) => $query->where('active', true))
                ->when($this->status === 'inactive', fn (Builder $query) => $query->where('active', false))
                ->when(array_key_exists($this->role, UserRole::options()), fn (Builder $query) => $query->where('role', $this->role))
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(15),
            'roleOptions' => UserRole::options(),
        ])->title('Usuários · BULAI');
    }
}
