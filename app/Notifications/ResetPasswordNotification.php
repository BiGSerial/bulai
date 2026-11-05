<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/** E-mail de redefinição de senha em português; o link expira em config('auth.passwords.users.expire') minutos. */
final class ResetPasswordNotification extends ResetPassword
{
    public function toMail(mixed $notifiable): MailMessage
    {
        $expire = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject('Redefinição de senha · '.config('app.name'))
            ->greeting('Olá!')
            ->line('Recebemos um pedido para redefinir a senha da sua conta no '.config('app.name').'.')
            ->action('Redefinir minha senha', $this->resetUrl($notifiable))
            ->line("O link vale por {$expire} minutos.")
            ->line('Se você não pediu a redefinição, ignore este e-mail: sua senha continua a mesma.')
            ->salutation('Equipe '.config('app.name'));
    }
}
