<?php

namespace App\Livewire\Admin\Users;

use App\Models\User;
use App\Services\Users\UserAccountService;
use App\UserRole;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Form extends Component
{
    #[Locked]
    public ?int $userId = null;

    /** Usuário recém-cadastrado cuja senha provisória está sendo exibida. */
    #[Locked]
    public ?int $createdUserId = null;

    public string $name = '';

    public string $email = '';

    public string $role = UserRole::User->value;

    /**
     * Senha provisória do cadastro recém-feito. Não é propriedade pública: não vai para o snapshot
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

    public function mount(?User $user = null): void
    {
        if ($user === null || ! $user->exists) {
            return;
        }

        $this->userId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = $user->role->value;
    }

    public function save(UserAccountService $service): mixed
    {
        if ($this->createdUserId !== null) {
            return $this->finishCreation();
        }

        $this->email = trim($this->email);
        $validated = $this->validate($this->rules(), messages: $this->messages(), attributes: $this->validationAttributes());

        if ($this->userId === null) {
            [$user, $temporaryPassword] = $service->create($validated, auth()->user());
            $this->createdUserId = $user->id;
            $this->temporaryPassword = $temporaryPassword;

            return null;
        }

        $user = $service->update(User::query()->findOrFail($this->userId), $validated, auth()->user());
        session()->flash('userFeedback', 'Usuário atualizado com sucesso.');

        return $this->redirectRoute('admin.users.show', $user, navigate: true);
    }

    public function finishCreation(): mixed
    {
        if ($this->createdUserId === null) {
            return null;
        }

        session()->flash('userFeedback', 'Usuário cadastrado com sucesso. No primeiro acesso, a pessoa precisará definir uma senha própria.');

        return $this->redirectRoute('admin.users.show', $this->createdUserId, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.admin.users.form', [
            'roleOptions' => UserRole::options(),
            'temporaryPassword' => $this->temporaryPassword,
        ])->title(($this->userId === null ? 'Novo usuário' : 'Editar usuário').' · BULAI');
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::in(array_keys(UserRole::options()))],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'required' => 'Preencha :attribute.',
            'email' => 'Informe um e-mail válido.',
        ];
    }

    /** @return array<string, string> */
    private function validationAttributes(): array
    {
        return [
            'name' => 'o nome',
            'email' => 'o e-mail',
            'role' => 'o perfil',
        ];
    }
}
