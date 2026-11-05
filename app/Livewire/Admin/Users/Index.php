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

    /** Ordenações disponíveis: chave => [coluna, direção]. */
    private const SORTS = [
        'name' => ['name', 'asc'],
        'recent_login' => ['last_login_at', 'desc'],
        'newest' => ['created_at', 'desc'],
    ];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: 'all')]
    public string $role = 'all';

    #[Url(as: 'ordem', except: 'name')]
    public string $sort = 'name';

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

    public function updatedSort(): void
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
        [$column, $direction] = self::SORTS[$this->sort] ?? self::SORTS['name'];

        $query = User::query()
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->whereLike('name', "%{$search}%")
                    ->orWhereLike('email', "%{$search}%");
            }))
            ->when($this->status === 'active', fn (Builder $query) => $query->where('active', true))
            ->when($this->status === 'inactive', fn (Builder $query) => $query->where('active', false))
            ->when($this->status === 'pending', fn (Builder $query) => $query->where('must_change_password', true))
            ->when(array_key_exists($this->role, UserRole::options()), fn (Builder $query) => $query->where('role', $this->role));

        return view('livewire.admin.users.index', [
            // Em ordem decrescente os nulos (nunca acessou) vão para o fim.
            'users' => (clone $query)->orderByRaw($direction === 'desc' ? "{$column} desc nulls last" : "{$column} asc")->orderBy('name')->orderBy('id')->paginate(15),
            'totals' => [
                'all' => User::query()->count(),
                'active' => User::query()->where('active', true)->count(),
                'admins' => User::query()->where('role', UserRole::Admin)->where('active', true)->count(),
                'pending' => User::query()->where('must_change_password', true)->count(),
            ],
            'roleOptions' => UserRole::options(),
        ])->title('Usuários · BULAI');
    }
}
