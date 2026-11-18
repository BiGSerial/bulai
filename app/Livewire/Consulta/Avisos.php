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
 * Avisos do paciente (lembretes de dose, fim de tratamento, remédio reconhecido pela administração), abertos pelo sininho
 * do cabeçalho. Cada aviso tem uma única ação grande; abrir já marca como lido. As preferências de lembrete (ligar/desligar,
 * e-mail, fuso e avisos no aparelho) ficam na tela Conta.
 */
class Avisos extends Component
{
    /** Fusos oferecidos (Brasil). Qualquer outro valor é recusado e cai no padrão. */
    public const TIMEZONES = PatientProfile::TIMEZONES;

    public ?string $mensagem = null;

    public function marcarTodosLidos(): void
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
        $this->mensagem = 'Todos os avisos foram marcados como lidos.';
    }

    /** Central de avisos: apaga todos os avisos da pessoa (lidos e não lidos). */
    public function limparTodos(): void
    {
        $apagados = auth()->user()->notifications()->delete();
        $this->mensagem = $apagados === 1 ? 'O aviso foi apagado.' : 'Todos os avisos foram apagados.';
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
            'fusoAtual' => $user->treatmentTimezone(),
        ]);
    }
}
