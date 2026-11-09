<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Avisa os administradores de que alguém se cadastrou pelo site e aguarda aprovação. Leva só nome e e-mail. */
final class RegistrationAwaitingApprovalNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly User $registeredUser) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $app = config('app.name');

        return (new MailMessage)
            ->subject('Novo cadastro aguardando aprovação · '.$app)
            ->markdown('mail.cadastro-pendente', [
                'adminName' => $notifiable->name, 'app' => $app,
                'name' => $this->registeredUser->name, 'email' => $this->registeredUser->email,
                'registeredAt' => $this->registeredUser->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
                'reviewUrl' => route('admin.users.show', $this->registeredUser),
            ]);
    }
}
