<?php

namespace App\Livewire\Admin\Users;

use App\Models\User;
use App\Services\Users\UserAccountService;
use App\UserApprovalStatus;
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

    /** Resultado da última aprovação/recusa feita na lista. */
    public ?string $feedback = null;

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

    /** Aprova o cadastro feito pelo site e avisa a pessoa por e-mail (se o envio falhar, a aprovação continua valendo). */
    public function approve(int $userId, UserAccountService $service): void
    {
        $user = $service->approve(User::query()->findOrFail($userId), auth()->user());
        $this->feedback = $service->emailApproval($user, auth()->user())
            ? "Cadastro de {$user->name} aprovado. Avisamos a pessoa por e-mail."
            : "Cadastro de {$user->name} aprovado, mas o e-mail de aviso não pôde ser enviado. Avise a pessoa por outro canal.";
    }

    /** Recusa o cadastro (para registrar um motivo, recuse pela página do usuário). */
    public function reject(int $userId, UserAccountService $service): void
    {
        $user = $service->reject(User::query()->findOrFail($userId), auth()->user());
        $this->feedback = "Cadastro de {$user->name} recusado. A pessoa continua sem acesso.";
    }

    /** Atalho do aviso de cadastros pendentes: mostra só quem aguarda aprovação. */
    public function showAwaitingApproval(): void
    {
        $this->reset('search', 'role');
        $this->status = 'awaiting_approval';
        $this->resetPage();
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
            ->when($this->status === 'active', fn (Builder $query) => $query->where('active', true)->where('approval_status', UserApprovalStatus::Approved))
            ->when($this->status === 'inactive', fn (Builder $query) => $query->where('active', false))
            ->when($this->status === 'pending', fn (Builder $query) => $query->where('must_change_password', true))
            ->when($this->status === 'awaiting_approval', fn (Builder $query) => $query->where('approval_status', UserApprovalStatus::Pending))
            ->when($this->status === 'rejected', fn (Builder $query) => $query->where('approval_status', UserApprovalStatus::Rejected))
            ->when(array_key_exists($this->role, UserRole::options()), fn (Builder $query) => $query->where('role', $this->role));

        return view('livewire.admin.users.index', [
            // Em ordem decrescente os nulos (nunca acessou) vão para o fim.
            'users' => (clone $query)->orderByRaw($direction === 'desc' ? "{$column} desc nulls last" : "{$column} asc")->orderBy('name')->orderBy('id')->paginate(15),
            'totals' => [
                'all' => User::query()->count(),
                'active' => User::query()->where('active', true)->where('approval_status', UserApprovalStatus::Approved)->count(),
                'admins' => User::query()->where('role', UserRole::Admin)->where('active', true)->count(),
                'pending' => User::query()->where('must_change_password', true)->count(),
                'awaitingApproval' => User::query()->where('approval_status', UserApprovalStatus::Pending)->count(),
            ],
            'roleOptions' => UserRole::options(),
            'effectiveDirection' => $direction,
        ])->title('Usuários · BULAI');
    }
}
