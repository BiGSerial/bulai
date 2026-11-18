<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileDevice;
use App\Models\UserAuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** O próprio aparelho se desconecta (saída da conta no aplicativo): o token deixa de valer na hora. */
final class DeviceController extends Controller
{
    public function destroy(Request $request): JsonResponse
    {
        /** @var MobileDevice $device */
        $device = $request->attributes->get('mobile_device');
        $device->delete();
        UserAuditEvent::query()->create(['user_id' => $device->user_id, 'actor_id' => $device->user_id, 'event' => UserAuditEvent::EVENT_MOBILE_DEVICE_DISCONNECTED, 'details' => ['device_id' => $device->id, 'device_name' => $device->device_name], 'ip' => $request->ip()]);

        return response()->json(['ok' => true]);
    }
}
