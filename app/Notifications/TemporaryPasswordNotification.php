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
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line($this->isNewAccount ? "Um administrador criou o seu acesso ao {$app}." : "Um administrador gerou uma nova senha provisória para o seu acesso ao {$app}.")
            ->line('Senha provisória: **'.$this->temporaryPassword.'**')
            ->action('Entrar no '.$app, route('login'))
            ->line('No primeiro acesso você precisará definir uma senha própria. Esta senha provisória deixa de valer depois disso.')
            ->line('Se você não esperava este e-mail, não use a senha e avise um administrador.')
            ->salutation('Equipe '.$app);
    }
}
