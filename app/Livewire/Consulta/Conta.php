<?php

namespace App\Livewire\Consulta;

use App\Models\CaregiverLink;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\Users\UserAccountService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

/**
 * Conta do paciente, o 4º destino da barra (igual ao aplicativo Android): quem está conectado, como quer ser chamado,
 * aparência, lembretes e avisos no aparelho, senha, atalhos (perguntas, resumo, cuidadores, sobre…), sair e apagar a
 * conta. As preferências de lembrete saíram da tela de Avisos, que ficou só com a lista.
 */
class Conta extends Component
{
    public string $nomeDeChamada = '';

    public bool $lembretes = true;

    public bool $email = false;

    public string $fuso = 'America/Sao_Paulo';

    public string $senhaAtual = '';

    public string $novaSenha = '';

    public string $novaSenha_confirmation = '';

    public string $senhaParaApagar = '';

    public ?string $mensagem = null;

    public function mount(): void
    {
        $user = auth()->user();
        $this->nomeDeChamada = (string) $user->preferred_name;
        $profile = $user->patientProfile;
        $this->lembretes = (bool) ($profile?->reminders_enabled ?? true);
        $this->email = (bool) ($profile?->reminders_email ?? false);
        $this->fuso = array_key_exists((string) $profile?->timezone, PatientProfile::TIMEZONES) ? (string) $profile->timezone : (string) config('bulai.treatment.default_timezone');
    }

    /** Nome de chamada (mesmas regras do aplicativo): vazio volta ao primeiro nome. */
    public function salvarNome(UserAccountService $service): void
    {
        $this->validate(['nomeDeChamada' => ['nullable', 'string', 'max:40', 'regex:/^[\pL\pM\s\'.-]+$/u']], [
            'nomeDeChamada.regex' => 'Use só letras, espaços, apóstrofo, ponto ou hífen.',
        ], ['nomeDeChamada' => 'nome de chamada']);

        /** @var User $user */
        $user = auth()->user();
        $service->updatePreferredName($user, $this->nomeDeChamada);
        $this->nomeDeChamada = (string) $user->refresh()->preferred_name;
        $this->mensagem = $user->preferred_name === null ? 'Pronto: vamos chamar você de '.$user->callName().'.' : 'Pronto: agora chamamos você de '.$user->callName().'.';
    }

    public function updatedLembretes(bool $value): void
    {
        $this->salvarPreferencias(['reminders_enabled' => $value]);
        $this->mensagem = $value ? 'Lembretes ligados.' : 'Lembretes desligados. Você não receberá avisos de horário.';
    }

    public function updatedEmail(bool $value): void
    {
        $this->salvarPreferencias(['reminders_email' => $value]);
        $this->mensagem = $value ? 'Você também receberá os lembretes por e-mail.' : 'Lembretes só no aplicativo.';
    }

    public function updatedFuso(string $value): void
    {
        if (! array_key_exists($value, PatientProfile::TIMEZONES)) {
            $this->fuso = (string) config('bulai.treatment.default_timezone');

            return;
        }
        $this->salvarPreferencias(['timezone' => $value]);
        $this->mensagem = 'Horário ajustado para '.PatientProfile::TIMEZONES[$value].'.';
    }

    /** @param array<string, mixed> $attributes */
    private function salvarPreferencias(array $attributes): void
    {
        PatientProfile::query()->where('user_id', auth()->id())->whereNotNull('health_consent_at')->update($attributes);
    }

    /** Troca de senha voluntária (mesmas regras de "Minha conta"): as outras sessões e aparelhos caem, esta continua. */
    public function trocarSenha(UserAccountService $service): void
    {
        $this->validate([
            'senhaAtual' => ['required', 'string', 'current_password'],
            'novaSenha' => ['required', 'string', 'confirmed', 'different:senhaAtual', Password::defaults()],
        ], [
            'novaSenha.different' => 'A nova senha deve ser diferente da senha atual.',
        ], [
            'senhaAtual' => 'senha atual',
            'novaSenha' => 'nova senha',
        ]);

        $service->changePassword(auth()->user(), $this->novaSenha, session()->getId());
        $this->reset('senhaAtual', 'novaSenha', 'novaSenha_confirmation');
        $this->mensagem = 'Senha alterada. As outras sessões abertas com a sua conta foram encerradas; esta continua conectada.';
    }

    /** Apagar a conta (LGPD): pede a senha; administrador não se apaga por aqui. */
    public function apagarConta(UserAccountService $service): ?RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();
        if ($user->isAdmin()) {
            $this->addError('senhaParaApagar', 'Contas de administrador não podem ser apagadas por aqui. Fale com outro administrador.');

            return null;
        }
        if (! Hash::check($this->senhaParaApagar, $user->password)) {
            $this->addError('senhaParaApagar', 'A senha não confere.');

            return null;
        }

        $service->deleteOwnAccount($user);
        Auth::guard('web')->logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Sua conta foi apagada. Seus dados pessoais e de saúde foram removidos.');
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.consulta.conta', [
            'user' => $user,
            'consentido' => $user->hasHealthConsent(),
            'fusos' => PatientProfile::TIMEZONES,
            // Modo cuidador: pessoas cujos remédios esta conta pode abrir (vínculo ativo, "ver remédios" e consentimento).
            'cuidados' => CaregiverLink::query()->active()->where('caregiver_id', $user->id)->where('can_view_medications', true)
                ->whereHas('patient.patientProfile', fn ($query) => $query->whereNotNull('health_consent_at'))->with('patient:id,name')->orderBy('id')->get(),
        ]);
    }
}
