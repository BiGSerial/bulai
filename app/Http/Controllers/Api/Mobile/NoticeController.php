<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Livewire\Consulta\Avisos;
use App\Models\PatientProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

/**
 * Avisos no aplicativo nativo: a central (lista, abrir, marcar lidos, apagar), as preferências de lembrete e a consulta
 * de avisos novos que o trabalho em segundo plano usa para mostrar notificações do sistema.
 *
 * Cada aviso traz `destination` = {route, params}: o nome da rota do site (`consulta.hoje`, `consulta.perfil`…), que o
 * aplicativo traduz para a sua própria tela.
 */
final class NoticeController extends Controller
{
    private const LOOKBACK_HOURS = 24;

    private const NEW_LIMIT = 20;

    private const LIST_LIMIT = 30;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->patientProfile;
        $timezone = $user->treatmentTimezone();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'health_consent' => $user->hasHealthConsent(),
            'preferences' => [
                'reminders' => (bool) ($profile?->reminders_enabled ?? true),
                'email' => (bool) ($profile?->reminders_email ?? false),
                'timezone' => $timezone,
            ],
            'timezones' => collect(PatientProfile::TIMEZONES)->map(fn (string $label, string $id): array => ['id' => $id, 'label' => $label])->values(),
            'notices' => $user->notifications()->latest()->limit(self::LIST_LIMIT)->get()
                ->map(fn (DatabaseNotification $notice): array => $this->notice($notice) + [
                    'read' => $notice->read_at !== null,
                    'when' => Avisos::quando($notice->created_at, $timezone),
                    'action' => Avisos::acao($notice),
                ])->values(),
        ])->header('Cache-Control', 'no-store');
    }

    /** Avisos não lidos que chegaram depois de `desde` (no máximo 24 h), para notificações do sistema. */
    public function fresh(Request $request): JsonResponse
    {
        $data = $request->validate(['desde' => ['nullable', 'date']]);
        $since = CarbonImmutable::now()->subHours(self::LOOKBACK_HOURS);
        if (isset($data['desde']) && CarbonImmutable::parse($data['desde'])->greaterThan($since)) {
            $since = CarbonImmutable::parse($data['desde']);
        }

        $notices = $request->user()->unreadNotifications()->where('created_at', '>', $since)->latest()->limit(self::NEW_LIMIT)->get()
            ->map(fn (DatabaseNotification $notice): array => $this->notice($notice))->values();

        return response()->json(['checked_at' => CarbonImmutable::now()->toIso8601String(), 'notices' => $notices])->header('Cache-Control', 'no-store');
    }

    /** Abrir um aviso marca como lido e devolve para onde ir. */
    public function open(Request $request, string $notice): JsonResponse
    {
        $found = $request->user()->notifications()->whereKey($notice)->firstOrFail();
        $found->markAsRead();

        return response()->json(['destination' => $this->destination($found)]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->index($request);
    }

    public function destroy(Request $request, string $notice): JsonResponse
    {
        $request->user()->notifications()->whereKey($notice)->delete();

        return $this->index($request);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $request->user()->notifications()->delete();

        return $this->index($request);
    }

    /** Lembretes ligados, cópia por e-mail e fuso. Só vale com o consentimento de saúde dado (como no site). */
    public function preferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reminders' => ['sometimes', 'boolean'],
            'email' => ['sometimes', 'boolean'],
            'timezone' => ['sometimes', 'string', Rule::in(array_keys(PatientProfile::TIMEZONES))],
        ]);
        $attributes = array_filter([
            'reminders_enabled' => $data['reminders'] ?? null,
            'reminders_email' => $data['email'] ?? null,
            'timezone' => $data['timezone'] ?? null,
        ], fn (mixed $value): bool => $value !== null);

        if ($attributes !== []) {
            PatientProfile::query()->where('user_id', $request->user()->id)->whereNotNull('health_consent_at')->update($attributes);
            $request->user()->unsetRelation('patientProfile');
        }

        return $this->index($request);
    }

    /**
     * @return array{id: string, kind: string, title: string, body: string, url: string, destination: array{route: string, params: object}, created_at: string|null}
     */
    private function notice(DatabaseNotification $notice): array
    {
        $data = (array) $notice->data;
        $destination = $this->destination($notice);

        return [
            'id' => $notice->id,
            'kind' => (string) ($data['kind'] ?? 'aviso'),
            'title' => (string) ($data['title'] ?? 'Aviso do BULA-IA'),
            'body' => (string) ($data['body'] ?? ''),
            'url' => route($destination['route'], (array) $destination['params'], false),
            'destination' => $destination,
            'created_at' => $notice->created_at?->toIso8601String(),
        ];
    }

    /** @return array{route: string, params: object} */
    private function destination(DatabaseNotification $notice): array
    {
        $route = $notice->data['route'] ?? null;

        // `params` sempre como objeto JSON ({}), nunca lista vazia ([]).
        return is_string($route) && Route::has($route)
            ? ['route' => $route, 'params' => (object) ($notice->data['route_params'] ?? [])]
            : ['route' => 'consulta.hoje', 'params' => (object) []];
    }
}
