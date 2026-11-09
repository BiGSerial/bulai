<?php

namespace App\Notifications;

use App\Models\CaregiverLink;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Aviso ao paciente: alguém usou o código do convite e espera a aprovação (nada é compartilhado antes disso). */
final class CaregiverRequestNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly CaregiverLink $link) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return WebPushChannel::appendTo($notifiable, ['database']);
    }

    /** @return array<string, mixed> */
    public function toDatabase(mixed $notifiable): array
    {
        $name = trim((string) $this->link->caregiver?->name) ?: 'Uma pessoa';

        return [
            'kind' => 'caregiver_request',
            'title' => $name.' quer acompanhar seus remédios',
            'body' => 'Nada é mostrado antes da sua aprovação. Abra "Quem cuida de mim" para aprovar e escolher o que pode ver.',
            'caregiver_link_id' => $this->link->id,
            'route' => 'consulta.cuidadores',
        ];
    }
}
