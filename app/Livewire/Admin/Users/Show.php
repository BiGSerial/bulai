<?php

namespace App\Livewire\Admin\Users;

use App\Models\Consultation\InteractionConsultation;
use App\Models\Consultation\MedicationQuestion;
use App\Models\User;
use App\Services\Users\TemporaryPasswordEmailException;
use App\Services\Users\UserAccountService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Show extends Component
{
    use WithPagination;

    #[Locked]
    public int $userId;

    /** Aba visível: dados, acesso ou historico. */
    #[Url(as: 'aba', except: 'dados')]
    public string $tab = 'dados';

    /** Motivo informado ao desativar o acesso (vai para a auditoria). */
    public string $reason = '';

    /** Motivo informado ao recusar o cadastro (só vai para a auditoria; a pessoa não o recebe). */
    public string $rejectionReason = '';

    /** Enviar a nova senha provisória por e-mail, além de exibi-la uma vez. */
    public bool $sendByEmail = false;

    public ?string $feedback = null;

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
        $this->updatedTab();
    }

    public function updatedTab(): void
    {
        if (! in_array($this->tab, ['dados', 'acesso', 'historico'], true)) {
            $this->tab = 'dados';
        }
    }

    public function toggleActive(UserAccountService $service): void
    {
        $user = User::query()->findOrFail($this->userId);
        $wasActive = $user->isActive();
        $service->setActive($user, ! $wasActive, auth()->user(), $wasActive ? $this->reason : null);
        $this->reset('reason');
        $this->feedback = $wasActive ? 'Acesso desativado. A pessoa foi desconectada.' : 'Acesso reativado.';
    }

    /** Aprova o cadastro feito pelo site e avisa a pessoa por e-mail (se o envio falhar, a aprovação continua valendo). */
    public function approve(UserAccountService $service): void
    {
        $user = $service->approve(User::query()->findOrFail($this->userId), auth()->user());
        if ($service->emailApproval($user, auth()->user())) {
            $this->feedback = 'Cadastro aprovado. Avisamos a pessoa por e-mail que o acesso foi liberado.';
        } else {
            $this->feedback = 'Cadastro aprovado.';
            $this->addError('approval', 'O e-mail de aviso não pôde ser enviado. Avise a pessoa por outro canal: ela já pode entrar.');
        }
    }

    public function reject(UserAccountService $service): void
    {
        $service->reject(User::query()->findOrFail($this->userId), auth()->user(), $this->rejectionReason);
        $this->reset('rejectionReason');
        $this->feedback = 'Cadastro recusado. A pessoa continua sem acesso.';
    }

    public function issueTemporaryPassword(UserAccountService $service): void
    {
        $user = User::query()->findOrFail($this->userId);
        $this->temporaryPassword = $service->issueTemporaryPassword($user, auth()->user());
        if ($this->sendByEmail) {
            try {
                $service->emailTemporaryPassword($user, $this->temporaryPassword, false, auth()->user());
            } catch (TemporaryPasswordEmailException $exception) {
                // A senha continua válida e aparece no modal; o aviso explica por que o e-mail não saiu.
                $this->addError('temporaryPassword', $exception->getMessage());
            }
        }
    }

    public function forcePasswordChange(UserAccountService $service): void
    {
        $service->forcePasswordChange(User::query()->findOrFail($this->userId), auth()->user());
        $this->feedback = 'A pessoa precisará definir uma nova senha no próximo acesso.';
    }

    public function terminateSessions(UserAccountService $service): void
    {
        $user = User::query()->findOrFail($this->userId);
        $service->terminateSessions($user, auth()->user(), $user->is(auth()->user()) ? session()->getId() : null);
        $this->feedback = 'As sessões foram encerradas.';
    }

    public function render(): View
    {
        $user = User::query()->with(['creator:id,name', 'approvalReviewer:id,name'])->findOrFail($this->userId);

        return view('livewire.admin.users.show', [
            'user' => $user,
            'isCurrentUser' => $user->is(auth()->user()),
            'history' => $user->auditEvents()->with('actor:id,name')->orderByDesc('created_at')->orderByDesc('id')->paginate(10, pageName: 'historico'),
            'usage' => [
                'consultations' => InteractionConsultation::query()->where('user_id', $user->id)->count(),
                'questions' => MedicationQuestion::query()->where('user_id', $user->id)->count(),
                'healthConsent' => $user->hasHealthConsent(),
            ],
            'temporaryPassword' => $this->temporaryPassword,
            'mailConfigured' => config('mail.default') !== null && config('mail.default') !== 'array',
        ])->title($user->name.' · BULAI');
    }
}
