<?php

namespace App\Notifications;

use App\Models\PatientMedication;
use App\Notifications\Channels\HasDedupeKey;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Lembrete de dose: "Hora do remédio X às HH:MM". Sempre no aplicativo (`database`); por e-mail quando o paciente pediu.
 * Uma por dose (remédio + horário), garantido pela chave de deduplicação.
 */
final class MedicationReminderNotification extends Notification implements HasDedupeKey
{
    use Queueable;

    public function __construct(
        private readonly PatientMedication $medication,
        private readonly CarbonImmutable $scheduledFor,
        private readonly bool $byEmail = false,
    ) {}

    public static function keyFor(PatientMedication $medication, CarbonImmutable $scheduledFor): string
    {
        return 'dose:'.$medication->id.':'.$scheduledFor->utc()->toIso8601String();
    }

    public function dedupeKey(): ?string
    {
        return self::keyFor($this->medication, $this->scheduledFor);
    }

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return $this->byEmail ? ['database', 'mail'] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(mixed $notifiable): array
    {
        $time = $this->scheduledFor->format('H:i');
        $dose = trim((string) $this->medication->dosage);

        return [
            'kind' => 'dose',
            'title' => 'Hora do remédio: '.$this->medication->displayName(),
            'body' => sprintf('Às %s%s. Depois de tomar, marque "Tomei" em Remédios de hoje.', $time, $dose !== '' ? ', '.$dose : ''),
            'medication_id' => $this->medication->id,
            'scheduled_for' => $this->scheduledFor->utc()->toIso8601String(),
            'time' => $time,
            'route' => 'consulta.hoje',
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $data = $this->toDatabase($notifiable);

        return (new MailMessage)
            ->subject($data['title'].' às '.$data['time'].' · '.config('app.name'))
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line($data['body'])
            ->action('Ver remédios de hoje', route('consulta.hoje'))
            ->line('Para parar de receber lembretes por e-mail, desative "Também por e-mail" em Avisos no aplicativo.')
            ->line(config('bulai.guidelines.disclaimer'))
            ->salutation('Equipe '.config('app.name'));
    }
}
