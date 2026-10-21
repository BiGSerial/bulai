<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /**
     * Rota de destino após o login e em "/": quem tem troca de senha pendente vai ao primeiro acesso;
     * depois disso, ADMIN vai ao admin e USER, à Minha conta.
     */
    public function homeRouteName(): string
    {
        if ($this->must_change_password) {
            return 'first-access';
        }

        return $this->isAdmin() ? 'admin.documents.index' : 'account';
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

    public function auditEvents(): HasMany
    {
        return $this->hasMany(UserAuditEvent::class);
    }
}
