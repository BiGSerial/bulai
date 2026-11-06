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

    /** Ordenações disponíveis (cabeçalhos da tabela): chave => [coluna, direção padrão]. */
    private const SORTS = [
        'name' => ['name', 'asc'],
        'email' => ['email', 'asc'],
        'role' => ['role', 'asc'],
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

    /** Sentido escolhido ao clicar duas vezes no mesmo cabeçalho; vazio = sentido padrão da coluna. */
    #[Url(as: 'sentido', except: '')]
    public string $direction = '';

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
        $this->direction = '';
        $this->resetPage();
    }

    /** Clique no cabeçalho de uma coluna: ordena por ela; o segundo clique inverte o sentido. */
    public function sortBy(string $field): void
    {
        if (! array_key_exists($field, self::SORTS)) {
            return;
        }
        if ($this->sort === $field) {
            $this->direction = $this->effectiveDirection() === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $field;
            $this->direction = '';
        }
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'role');
        $this->resetPage();
    }

    private function effectiveDirection(): string
    {
        $default = (self::SORTS[$this->sort] ?? self::SORTS['name'])[1];

        return in_array($this->direction, ['asc', 'desc'], true) ? $this->direction : $default;
    }

    public function toggleActive(int $userId, UserAccountService $service): void
    {
        $user = User::query()->findOrFail($userId);
        $service->setActive($user, ! $user->isActive(), auth()->user());
    }

    public function render(): View
    {
        $search = trim($this->search);
        [$column] = self::SORTS[$this->sort] ?? self::SORTS['name'];
        $direction = $this->effectiveDirection();

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
            'effectiveDirection' => $direction,
        ])->title('Usuários · BULAI');
    }
}
