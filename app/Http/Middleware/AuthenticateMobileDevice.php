<?php

namespace App\Http\Middleware;

use App\Models\MobileDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Token do aparelho Android (`Authorization: Bearer bulai_app_…`). O dono precisa continuar com acesso ativo: conta
 * desativada ou cadastro não aprovado recebe 401 e o aplicativo desliga os alarmes.
 */
class AuthenticateMobileDevice
{
    /** Intervalo mínimo entre gravações de `last_seen_at`, para não escrever no banco a cada sincronização. */
    private const SEEN_RESOLUTION_MINUTES = 5;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $device = MobileDevice::findByToken((string) $request->bearerToken());
        $user = $device?->user;

        if ($device === null || $user === null || $user->signInBlockedMessage() !== null) {
            return response()->json(['message' => 'Aparelho desconectado. Entre de novo no aplicativo.'], 401);
        }

        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinutes(self::SEEN_RESOLUTION_MINUTES))) {
            $device->forceFill(['last_seen_at' => now()])->save();
        }

        $request->attributes->set('mobile_device', $device);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
