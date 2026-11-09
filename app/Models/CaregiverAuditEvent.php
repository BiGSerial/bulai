<?php

namespace App\Models;

use Database\Factories\CaregiverAuditEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento da trilha de auditoria do modo cuidador: quem (`actor_id`), quando (`created_at`), sobre qual paciente
 * (`patient_id`) e em qual vínculo. Só recebe inclusões (gravadas por `CaregiverLinkService::audit()`).
 */
#[Fillable(['caregiver_link_id', 'patient_id', 'actor_id', 'event', 'details', 'ip'])]
class CaregiverAuditEvent extends Model
{
    /** @use HasFactory<CaregiverAuditEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const EVENT_INVITE_CREATED = 'invite_created';

    public const EVENT_INVITE_CANCELED = 'invite_canceled';

    public const EVENT_INVITE_ACCEPTED = 'invite_accepted';

    public const EVENT_APPROVED = 'approved';

    public const EVENT_REJECTED = 'rejected';

    public const EVENT_PERMISSIONS_CHANGED = 'permissions_changed';

    public const EVENT_REVOKED = 'revoked';

    public const EVENT_LEFT = 'left';

    public const EVENT_DOSE_MARKED = 'dose_marked';

    public const EVENT_DOSE_UNMARKED = 'dose_unmarked';

    public const EVENT_MEDICATION_UPDATED = 'medication_updated';

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(CaregiverLink::class, 'caregiver_link_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Frase do histórico para o paciente ("Ana marcou Losartana das 08:00 como tomada"). */
    public function describe(): string
    {
        $who = CaregiverLink::firstName($this->actor);
        $details = (array) ($this->details ?? []);
        $medicine = (string) ($details['medication'] ?? 'um remédio');
        $time = isset($details['time']) ? ' das '.$details['time'] : '';

        return match ($this->event) {
            self::EVENT_INVITE_CREATED => 'Convite criado (vale por 48 horas)',
            self::EVENT_INVITE_CANCELED => 'Convite cancelado',
            self::EVENT_INVITE_ACCEPTED => $who.' usou o código do convite',
            self::EVENT_APPROVED => 'Você aprovou '.CaregiverLink::firstName($this->link?->caregiver).' ('.self::permissionsText($details).')',
            self::EVENT_REJECTED => 'Pedido recusado',
            self::EVENT_PERMISSIONS_CHANGED => 'Permissões alteradas ('.self::permissionsText($details).')',
            self::EVENT_REVOKED => 'Acesso retirado',
            self::EVENT_LEFT => $who.' deixou de acompanhar você',
            self::EVENT_DOSE_MARKED => $who.' marcou '.$medicine.$time.' como '.(($details['status'] ?? '') === MedicationDoseEvent::STATUS_SKIPPED ? 'pulado' : 'tomado'),
            self::EVENT_DOSE_UNMARKED => $who.' desfez a marcação de '.$medicine.$time,
            self::EVENT_MEDICATION_UPDATED => $who.' mudou a dose ou os horários de '.$medicine,
            default => str($this->event)->headline()->toString(),
        };
    }

    /** @param array<string, mixed> $details */
    private static function permissionsText(array $details): string
    {
        $labels = ['can_receive_alerts' => 'avisos de atraso', 'can_view_medications' => 'ver remédios', 'can_manage_medications' => 'marcar e editar'];
        $granted = array_values(array_filter(array_map(fn (string $key): ?string => ($details[$key] ?? false) ? $labels[$key] : null, array_keys($labels))));

        return $granted === [] ? 'nenhuma permissão' : implode(', ', $granted);
    }
}
