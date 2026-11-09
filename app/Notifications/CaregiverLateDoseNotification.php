<?php

namespace App\Notifications;

use App\Models\CaregiverLink;
use App\Models\PatientMedication;
use App\Notifications\Channels\HasDedupeKey;
use App\Notifications\Channels\WebPushChannel;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso a quem cuida: uma dose do paciente passou do horário sem marcação. No aplicativo (sino) e por Web Push,
 * como os lembretes do próprio paciente; um por vínculo + remédio + horário (chave de deduplicação).
 */
final class CaregiverLateDoseNotification extends Notification implements HasDedupeKey
{
    use Queueable;

    public function __construct(
        private readonly CaregiverLink $link,
        private readonly PatientMedication $medication,
        private readonly CarbonImmutable $scheduledFor,
    ) {}

    public static function keyFor(CaregiverLink $link, PatientMedication $medication, CarbonImmutable $scheduledFor): string
    {
        return 'cuidador:'.$link->id.':'.$medication->id.':'.$scheduledFor->utc()->toIso8601String();
    }

    public function dedupeKey(): ?string
    {
        return self::keyFor($this->link, $this->medication, $this->scheduledFor);
    }

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return WebPushChannel::appendTo($notifiable, ['database']);
    }

    /** @return array<string, mixed> */
    public function toDatabase(mixed $notifiable): array
    {
        $time = $this->scheduledFor->format('H:i');
        $dose = trim((string) $this->medication->dosage);
        $patient = CaregiverLink::firstName($this->link->patient);

        return [
            'kind' => 'caregiver_late',
            'title' => 'Remédio atrasado: '.$patient,
            'body' => sprintf('%s das %s%s ainda não foi marcado por %s.', $this->medication->displayName(), $time, $dose !== '' ? ' ('.$dose.')' : '', $patient),
            'caregiver_link_id' => $this->link->id,
            'patient_id' => $this->link->patient_id,
            'medication_id' => $this->medication->id,
            'scheduled_for' => $this->scheduledFor->utc()->toIso8601String(),
            'time' => $time,
            'route' => 'consulta.cuidando.hoje',
            'route_params' => ['vinculo' => $this->link->id],
        ];
    }
}
