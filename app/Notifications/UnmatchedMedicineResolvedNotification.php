<?php

namespace App\Notifications;

use App\Models\PatientMedication;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso ao paciente quando a administração liga ao catálogo um remédio que ele cadastrou pelo nome:
 * a partir daí o remédio tem bula simples e entra no cruzamento de interações.
 */
final class UnmatchedMedicineResolvedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly PatientMedication $medication) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'kind' => 'medicine_resolved',
            'title' => 'Seu remédio foi reconhecido',
            'body' => sprintf('%s agora está ligado à bula oficial de %s. Você já pode ver a bula simples e as interações.', (string) $this->medication->name_as_written, $this->medication->displayName()),
            'medication_id' => $this->medication->id,
            'medicine_id' => $this->medication->medicine_id,
            'route' => 'consulta.perfil',
        ];
    }
}
