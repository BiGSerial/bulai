<?php

namespace App\Models;

use Database\Factories\CaregiverLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vínculo de cuidado entre um paciente e quem cuida dele (modo cuidador).
 *
 * Ciclo: o paciente gera um convite (`INVITED`, código de uso único por 48 h) → quem cuida informa o código (`PENDING`)
 * → o paciente aprova escolhendo as permissões (`ACTIVE`). O paciente pode recusar ou revogar (`REVOKED`) e quem cuida
 * pode sair (`LEFT`) a qualquer momento; um convite trocado por outro ou cancelado fica `CANCELED`.
 *
 * Acesso a dados de saúde só com vínculo `ACTIVE`, a permissão correspondente E o consentimento de saúde do paciente.
 */
#[Fillable(['patient_id', 'caregiver_id', 'status', 'invite_code_hash', 'invite_code', 'invite_expires_at', 'can_receive_alerts', 'can_view_medications', 'can_manage_medications', 'accepted_at', 'approved_at', 'ended_at', 'ended_by'])]
#[Hidden(['invite_code_hash', 'invite_code'])]
class CaregiverLink extends Model
{
    /** @use HasFactory<CaregiverLinkFactory> */
    use HasFactory;

    public const STATUS_INVITED = 'INVITED';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_REVOKED = 'REVOKED';

    public const STATUS_LEFT = 'LEFT';

    public const STATUS_CANCELED = 'CANCELED';

    /** Receber o aviso quando uma dose do paciente atrasar. */
    public const PERMISSION_ALERTS = 'can_receive_alerts';

    /** Ver os remédios e os horários do paciente. */
    public const PERMISSION_VIEW = 'can_view_medications';

    /** Marcar doses ("Tomei"/"Pular") e editar dose e horários dos remédios. */
    public const PERMISSION_MANAGE = 'can_manage_medications';

    /** Rótulos das permissões, na ordem em que aparecem para o paciente. */
    public const PERMISSIONS = [
        self::PERMISSION_ALERTS => 'Receber aviso quando eu atrasar um remédio',
        self::PERMISSION_VIEW => 'Ver meus remédios e horários',
        self::PERMISSION_MANAGE => 'Marcar doses e mudar dose e horários dos meus remédios',
    ];

    protected function casts(): array
    {
        return [
            'invite_code' => 'encrypted',
            'invite_expires_at' => 'datetime',
            'can_receive_alerts' => 'boolean',
            'can_view_medications' => 'boolean',
            'can_manage_medications' => 'boolean',
            'accepted_at' => 'datetime',
            'approved_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function caregiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caregiver_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(CaregiverAuditEvent::class);
    }

    /** Vínculos aprovados (dão acesso conforme as permissões). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Convite ainda utilizável: não usado, não cancelado e dentro das 48 h. */
    public function isUsableInvite(): bool
    {
        return $this->status === self::STATUS_INVITED && $this->invite_expires_at !== null && $this->invite_expires_at->isFuture();
    }

    /**
     * Quem cuida pode agir com esta permissão agora: vínculo ativo, permissão marcada pelo paciente e consentimento de
     * saúde do paciente em vigor (sem consentimento não há dado de saúde para compartilhar).
     */
    public function allows(string $permission): bool
    {
        return $this->isActive()
            && array_key_exists($permission, self::PERMISSIONS)
            && (bool) $this->getAttribute($permission)
            && $this->patient?->hasHealthConsent() === true;
    }

    /** Código para mostrar ao paciente, em dois grupos ("1234 5678"). */
    public static function formatCode(string $code): string
    {
        return trim(chunk_split($code, 4, ' '));
    }

    /** Hash usado para conferir o código digitado (só dígitos contam: espaços e traços são ignorados). */
    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', preg_replace('/\D+/', '', $code) ?? '', (string) config('app.key'));
    }

    /** Primeiro nome para as frases da tela ("cuidando de Maria"). */
    public static function firstName(?User $user): string
    {
        $name = trim((string) $user?->name);

        return $name === '' ? 'esta pessoa' : explode(' ', $name)[0];
    }
}
