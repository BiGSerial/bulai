<?php

namespace App\Notifications\Channels;

use App\Services\Push\WebPushSender;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Canal de notificação "Web Push": depois de gravado o aviso (canal `database`), acorda cada aparelho inscrito do paciente.
 * Nunca derruba a notificação: falha de push é registrada e o aviso continua no aplicativo.
 */
final class WebPushChannel
{
    public function __construct(private readonly WebPushSender $sender) {}

    /**
     * Acrescenta o canal às vias da notificação quando há push configurado e o paciente tem aparelhos inscritos.
     *
     * @param  list<string>  $channels
     * @return list<string|class-string>
     */
    public static function appendTo(mixed $notifiable, array $channels): array
    {
        if (! app(WebPushSender::class)->isConfigured() || ! method_exists($notifiable, 'pushSubscriptions') || ! $notifiable->pushSubscriptions()->exists()) {
            return $channels;
        }

        return [...$channels, self::class];
    }

    public function send(mixed $notifiable, Notification $notification): void
    {
        foreach ($notifiable->pushSubscriptions()->get() as $subscription) {
            try {
                $this->sender->send($subscription);
            } catch (Throwable $exception) {
                Log::warning('web_push_channel_failed', ['subscription_id' => $subscription->id, 'error' => $exception::class]);
            }
        }
    }
}
