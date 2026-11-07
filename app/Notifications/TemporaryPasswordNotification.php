<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Entrega da senha provisória por e-mail, a pedido do administrador. A senha vai em texto puro no e-mail
 * (por isso é opcional e exige troca no primeiro acesso); nunca é gravada nem registrada em log.
 */
final class TemporaryPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $temporaryPassword,
        private readonly bool $isNewAccount,
    ) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $app = config('app.name');

        return (new MailMessage)
            ->subject(($this->isNewAccount ? 'Seu acesso ao ' : 'Nova senha provisória · ').$app)
            ->markdown('mail.senha-provisoria', [
                'name' => $notifiable->name, 'app' => $app, 'isNewAccount' => $this->isNewAccount,
                'password' => $this->temporaryPassword, 'loginUrl' => route('login'),
            ]);
    }
}
