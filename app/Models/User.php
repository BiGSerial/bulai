<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Consultation\InteractionConsultation;
use App\Models\Consultation\MedicationQuestion;
use App\Notifications\ResetPasswordNotification;
use App\UserApprovalStatus;
use App\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'active', 'must_change_password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'approval_status' => UserApprovalStatus::class,
            'approval_reviewed_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isActive(): bool
    {
        return $this->active === true;
    }

    /** Cadastro liberado: criado pelo administrador, anterior à aprovação ou aprovado depois do cadastro pelo site. */
    public function isApproved(): bool
    {
        return $this->approval_status === UserApprovalStatus::Approved;
    }

    public function isAwaitingApproval(): bool
    {
        return $this->approval_status === UserApprovalStatus::Pending;
    }

    public function isRejected(): bool
    {
        return $this->approval_status === UserApprovalStatus::Rejected;
    }

    /**
     * Motivo, em linguagem simples, de a pessoa não poder entrar (cadastro aguardando aprovação, recusado ou acesso
     * desativado); null quando pode. Só deve ser mostrado depois que a senha conferiu.
     */
    public function signInBlockedMessage(): ?string
    {
        return match (true) {
            $this->isAwaitingApproval() => __('auth.awaiting_approval'),
            $this->isRejected() => __('auth.rejected'),
            ! $this->isActive() => __('auth.inactive'),
            default => null,
        };
    }

    /** A própria pessoa criou a conta pela tela pública de cadastro (não há administrador como autor). */
    public function registeredThemselves(): bool
    {
        return $this->created_by === null && $this->terms_accepted_at !== null;
    }

    /**
     * Rota de destino após o login e em "/": quem tem troca de senha pendente vai ao primeiro acesso;
     * depois disso, ADMIN vai ao admin e USER, ao Início do app do paciente.
     */
    public function homeRouteName(): string
    {
        if ($this->must_change_password) {
            return 'first-access';
        }

        return $this->isAdmin() ? 'admin.documents.index' : 'consulta.inicio';
    }

    /**
     * Indica se a pessoa já definiu a própria senha alguma vez (há evento password_changed na auditoria).
     *
     * Na tela de primeiro acesso, separa quem entra pela primeira vez (boas-vindas) de quem já usava
     * o sistema e recebeu uma nova senha provisória.
     */
    public function hasSetOwnPassword(): bool
    {
        return $this->auditEvents()->where('event', UserAuditEvent::EVENT_PASSWORD_CHANGED)->exists();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    /** Administrador que aprovou ou recusou o cadastro. */
    public function approvalReviewer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'approval_reviewed_by');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(UserAuditEvent::class);
    }

    /** Aparelhos inscritos em Web Push (avisos com o aplicativo fechado). */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function patientProfile(): HasOne
    {
        return $this->hasOne(PatientProfile::class);
    }

    public function allergies(): HasMany
    {
        return $this->hasMany(PatientAllergy::class);
    }

    public function currentMedications(): HasMany
    {
        return $this->hasMany(PatientMedication::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(PatientPrescription::class);
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(InteractionConsultation::class);
    }

    /** E-mail de redefinição em português (substitui a notificação padrão do framework). */
    public function sendPasswordResetNotification(mixed $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function medicationQuestions(): HasMany
    {
        return $this->hasMany(MedicationQuestion::class);
    }

    public function hasHealthConsent(): bool
    {
        return $this->patientProfile?->health_consent_at !== null;
    }

    public function doseEvents(): HasMany
    {
        return $this->hasMany(MedicationDoseEvent::class);
    }

    public function unmatchedMedicineReports(): HasMany
    {
        return $this->hasMany(UnmatchedMedicineReport::class);
    }

    /** Fuso horário do paciente para a agenda de doses (padrão em `config('bulai.treatment.default_timezone')`). */
    public function treatmentTimezone(): string
    {
        $timezone = (string) ($this->patientProfile?->timezone ?? '');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : (string) config('bulai.treatment.default_timezone');
    }
}
