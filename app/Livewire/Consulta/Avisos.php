<?php

namespace App\Livewire\Consulta;

use App\Models\PatientProfile;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/**
 * Avisos do paciente (lembretes de dose, fim de tratamento, remédio reconhecido pela administração) e as preferências
 * de lembrete (ligar/desligar, e-mail, fuso horário). Cada aviso tem uma única ação grande; abrir já marca como lido.
 */
class Avisos extends Component
{
    /** Fusos oferecidos (Brasil). Qualquer outro valor é recusado e cai no padrão. */
    public const TIMEZONES = [
        'America/Sao_Paulo' => 'Brasília (São Paulo, Rio, Minas, Sul)',
        'America/Manaus' => 'Manaus (Amazonas, Roraima, Rondônia)',
        'America/Belem' => 'Belém (Pará, Amapá)',
        'America/Fortaleza' => 'Fortaleza (Ceará, Maranhão, Piauí)',
        'America/Recife' => 'Recife (Pernambuco, Paraíba)',
        'America/Bahia' => 'Salvador (Bahia, Sergipe, Alagoas)',
        'America/Cuiaba' => 'Cuiabá (Mato Grosso)',
        'America/Campo_Grande' => 'Campo Grande (Mato Grosso do Sul)',
        'America/Rio_Branco' => 'Rio Branco (Acre)',
        'America/Noronha' => 'Fernando de Noronha',
    ];

    public bool $lembretes = true;

    public bool $email = false;

    public string $fuso = 'America/Sao_Paulo';

    public ?string $mensagem = null;

    public function mount(): void
    {
        $profile = auth()->user()->patientProfile;
        $this->lembretes = (bool) ($profile?->reminders_enabled ?? true);
        $this->email = (bool) ($profile?->reminders_email ?? false);
        $this->fuso = array_key_exists((string) $profile?->timezone, self::TIMEZONES) ? (string) $profile->timezone : (string) config('bulai.treatment.default_timezone');
    }

    public function marcarTodosLidos(): void
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
        $this->mensagem = 'Todos os avisos foram marcados como lidos.';
    }

    /** Abre a ação do aviso: marca como lido e leva à tela certa (hoje, meus remédios). */
    public function abrir(string $id): ?RedirectResponse
    {
        $aviso = auth()->user()->notifications()->whereKey($id)->first();
        if ($aviso === null) {
            return null;
        }
        $aviso->markAsRead();
        $route = (string) ($aviso->data['route'] ?? 'consulta.hoje');

        // Avisos do modo cuidador levam ao paciente certo (`route_params`); a tela confere o vínculo de novo ao abrir.
        return Route::has($route) ? redirect()->route($route, (array) ($aviso->data['route_params'] ?? [])) : redirect()->route('consulta.hoje');
    }

    public function apagar(string $id): void
    {
        auth()->user()->notifications()->whereKey($id)->delete();
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
        if (! array_key_exists($value, self::TIMEZONES)) {
            $this->fuso = (string) config('bulai.treatment.default_timezone');

            return;
        }
        $this->salvarPreferencias(['timezone' => $value]);
        $this->mensagem = 'Horário ajustado para '.self::TIMEZONES[$value].'.';
    }

    /** @param array<string, mixed> $attributes */
    private function salvarPreferencias(array $attributes): void
    {
        PatientProfile::query()->where('user_id', auth()->id())->whereNotNull('health_consent_at')->update($attributes);
    }

    /** "Hoje, 08:00" · "Ontem, 20:00" · "03/11, 08:00", no fuso do paciente. */
    public static function quando(CarbonInterface $at, string $timezone): string
    {
        $local = $at->copy()->timezone($timezone);
        $today = now($timezone)->startOfDay();

        return match (true) {
            $local->greaterThanOrEqualTo($today) => 'Hoje, '.$local->format('H:i'),
            $local->greaterThanOrEqualTo($today->subDay()) => 'Ontem, '.$local->format('H:i'),
            default => $local->format('d/m').', '.$local->format('H:i'),
        };
    }

    /** Rótulo do botão de ação de cada aviso. */
    public static function acao(DatabaseNotification $aviso): string
    {
        return match ((string) ($aviso->data['route'] ?? '')) {
            'consulta.perfil' => 'Ver meus remédios',
            'consulta.bula' => 'Ver a bula simples',
            'consulta.cuidadores' => 'Ver quem cuida de mim',
            'consulta.cuidando.hoje' => 'Abrir os remédios de quem você cuida',
            default => 'Ver remédios de hoje',
        };
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.consulta.avisos', [
            'avisos' => $user->notifications()->latest()->limit(30)->get(),
            'naoLidos' => $user->unreadNotifications()->count(),
            'consentido' => $user->hasHealthConsent(),
            'fusoAtual' => $user->treatmentTimezone(),
            'fusos' => self::TIMEZONES,
        ]);
    }
}
