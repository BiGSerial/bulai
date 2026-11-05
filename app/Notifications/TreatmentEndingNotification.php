<?php

namespace App\Notifications;

use App\Models\PatientMedication;
use App\Notifications\Channels\HasDedupeKey;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Aviso único no último dia de um tratamento com data de término: o paciente confere com quem receitou se deve continuar. */
final class TreatmentEndingNotification extends Notification implements HasDedupeKey
{
    use Queueable;

    public function __construct(
        private readonly PatientMedication $medication,
        private readonly CarbonImmutable $day,
        private readonly bool $byEmail = false,
    ) {}

    public static function keyFor(PatientMedication $medication, CarbonImmutable $day): string
    {
        return 'end:'.$medication->id.':'.$day->toDateString();
    }

    public function dedupeKey(): ?string
    {
        return self::keyFor($this->medication, $this->day);
    }

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return $this->byEmail ? ['database', 'mail'] : ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'kind' => 'treatment_end',
            'title' => 'Tratamento termina hoje: '.$this->medication->displayName(),
            'body' => 'Hoje é o último dia previsto na receita. Se tiver dúvida se deve continuar, fale com seu médico ou farmacêutico.',
            'medication_id' => $this->medication->id,
            'route' => 'consulta.perfil',
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $data = $this->toDatabase($notifiable);

        return (new MailMessage)
            ->subject($data['title'].' · '.config('app.name'))
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line($data['body'])
            ->action('Ver meus remédios', route('consulta.perfil'))
            ->line(config('bulai.guidelines.disclaimer'))
            ->salutation('Equipe '.config('app.name'));
    }
}
