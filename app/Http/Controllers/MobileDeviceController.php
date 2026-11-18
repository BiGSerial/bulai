<?php

namespace App\Http\Controllers;

use App\Models\MobileDevice;
use App\Models\UserAuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Liga o aplicativo Android à conta. Chamado pelo JavaScript da própria página (sessão + CSRF) dentro do WebView do
 * aplicativo; o token devolvido vai direto para a parte nativa (`window.BulaiNative.conectar`) e não é mostrado.
 */
final class MobileDeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'installation_id' => ['required', 'uuid'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);
        $user = $request->user();
        ['device' => $device, 'token' => $token] = MobileDevice::issueFor($user, $data);

        if ($device->wasRecentlyCreated) {
            UserAuditEvent::query()->create(['user_id' => $user->id, 'actor_id' => $user->id, 'event' => UserAuditEvent::EVENT_MOBILE_DEVICE_CONNECTED, 'details' => ['device_id' => $device->id, 'device_name' => $device->device_name], 'ip' => $request->ip()]);
        }

        return response()->json(['token' => $token, 'device_id' => $device->id, 'user_id' => $user->id])->header('Cache-Control', 'no-store');
    }
}
