<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;

/**
 * Avisos não lidos para o aparelho mostrar como notificação do sistema (aviso de cuidador, remédio ligado ao catálogo,
 * fim de tratamento). O aplicativo pede só o que chegou depois da última consulta (`desde`) e ignora `kind = dose`
 * quando os alarmes locais estão ligados, para não avisar duas vezes a mesma dose.
 */
final class NoticeController extends Controller
{
    private const LOOKBACK_HOURS = 24;

    private const LIMIT = 20;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['desde' => ['nullable', 'date']]);
        $since = CarbonImmutable::now()->subHours(self::LOOKBACK_HOURS);
        if (isset($data['desde']) && CarbonImmutable::parse($data['desde'])->greaterThan($since)) {
            $since = CarbonImmutable::parse($data['desde']);
        }

        $notices = $request->user()->unreadNotifications()->where('created_at', '>', $since)->latest()->limit(self::LIMIT)->get()
            ->map(function (DatabaseNotification $notification): array {
                $data = (array) $notification->data;
                $route = $data['route'] ?? null;

                return [
                    'id' => $notification->id,
                    'kind' => (string) ($data['kind'] ?? 'aviso'),
                    'title' => (string) ($data['title'] ?? 'Aviso do BULA-IA'),
                    'body' => (string) ($data['body'] ?? ''),
                    'url' => is_string($route) && Route::has($route) ? route($route, (array) ($data['route_params'] ?? []), false) : '/avisos',
                    'created_at' => $notification->created_at?->toIso8601String(),
                ];
            })->values();

        return response()->json(['checked_at' => CarbonImmutable::now()->toIso8601String(), 'notices' => $notices])->header('Cache-Control', 'no-store');
    }
}
