<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento da trilha de auditoria da gestão de usuários.
 *
 * Só recebe inclusões (gravadas por UserAccountService) e nunca guarda senha nem hash.
 */
#[Fillable(['user_id', 'actor_id', 'event', 'details', 'ip'])]
class UserAuditEvent extends Model
{
    public const UPDATED_AT = null;

    public const EVENT_CREATED = 'created';

    public const EVENT_UPDATED = 'updated';

    public const EVENT_ROLE_CHANGED = 'role_changed';

    public const EVENT_DEACTIVATED = 'deactivated';

    public const EVENT_REACTIVATED = 'reactivated';

    public const EVENT_TEMPORARY_PASSWORD_ISSUED = 'temporary_password_issued';

    public const EVENT_PASSWORD_CHANGED = 'password_changed';

    /** Valor de details.via em password_changed quando a senha provisória foi trocada na tela de primeiro acesso. */
    public const VIA_FIRST_ACCESS = 'first_access';

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
