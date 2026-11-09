<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Avisa a pessoa que se cadastrou pelo site de que um administrador aprovou o acesso. Não leva senha nem link mágico. */
final class AccountApprovedNotification extends Notification
{
    use Queueable;

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $app = config('app.name');

        return (new MailMessage)
            ->subject('Seu acesso foi liberado · '.$app)
            ->markdown('mail.cadastro-aprovado', [
                'name' => $notifiable->name, 'app' => $app, 'email' => $notifiable->email, 'loginUrl' => route('login'),
            ]);
    }
}
