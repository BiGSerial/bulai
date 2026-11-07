<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;

/** Inscrição do aparelho em Web Push e o texto do aviso que o service worker mostra ao receber um push vazio. */
final class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'url:https', 'max:2000']]);
        PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashFor($data['endpoint'])],
            ['user_id' => $request->user()->id, 'endpoint' => $data['endpoint'], 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255), 'failures' => 0],
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'url:https', 'max:2000']]);
        PushSubscription::query()->where('user_id', $request->user()->id)->where('endpoint_hash', PushSubscription::hashFor($data['endpoint']))->delete();

        return response()->json(['ok' => true]);
    }

    /** O aviso mais recente ainda não lido (últimos 30 min) do próprio paciente, para o service worker exibir. */
    public function latest(Request $request): JsonResponse
    {
        /** @var DatabaseNotification|null $notification */
        $notification = $request->user()->unreadNotifications()->where('created_at', '>=', now()->subMinutes(30))->latest()->first();
        $data = (array) ($notification?->data ?? []);
        $route = $data['route'] ?? null;

        return response()->json([
            'title' => (string) ($data['title'] ?? 'Aviso do BULA-IA'),
            'body' => (string) ($data['body'] ?? 'Você tem um aviso novo. Toque para abrir.'),
            'url' => is_string($route) && Route::has($route) ? route($route, [], false) : '/avisos',
            'tag' => $notification?->id ?? 'bulai-aviso',
        ])->header('Cache-Control', 'no-store');
    }
}
