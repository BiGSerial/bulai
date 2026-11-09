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

    public const EVENT_PASSWORD_CHANGE_FORCED = 'password_change_forced';

    public const EVENT_PASSWORD_RESET_REQUESTED = 'password_reset_requested';

    public const EVENT_PASSWORD_RESET = 'password_reset';

    public const EVENT_SESSIONS_TERMINATED = 'sessions_terminated';

    public const EVENT_TEMPORARY_PASSWORD_EMAILED = 'temporary_password_emailed';

    public const EVENT_LOGIN_BLOCKED = 'login_blocked';

    public const EVENT_SELF_REGISTERED = 'self_registered';

    public const EVENT_REGISTRATION_APPROVED = 'registration_approved';

    public const EVENT_REGISTRATION_REJECTED = 'registration_rejected';

    public const EVENT_APPROVAL_EMAILED = 'approval_emailed';

    /** Token da API (/api/v1) emitido ou revogado; fica na trilha do admin que agiu. */
    public const EVENT_API_TOKEN_ISSUED = 'api_token_issued';

    public const EVENT_API_TOKEN_REVOKED = 'api_token_revoked';

    /** Valor de details.via em password_changed quando a senha provisória foi trocada na tela de primeiro acesso. */
    public const VIA_FIRST_ACCESS = 'first_access';

    /** Valor de details.via em password_changed quando a senha foi redefinida pelo link "Esqueci minha senha". */
    public const VIA_RESET = 'reset';

    /** Rótulos em português para a trilha de auditoria (admin e Minha conta). */
    public static function label(string $event): string
    {
        return match ($event) {
            self::EVENT_CREATED => 'Usuário cadastrado',
            self::EVENT_UPDATED => 'Dados alterados',
            self::EVENT_ROLE_CHANGED => 'Perfil alterado',
            self::EVENT_DEACTIVATED => 'Acesso desativado',
            self::EVENT_REACTIVATED => 'Acesso reativado',
            self::EVENT_TEMPORARY_PASSWORD_ISSUED => 'Nova senha provisória gerada',
            self::EVENT_TEMPORARY_PASSWORD_EMAILED => 'Senha provisória enviada por e-mail',
            self::EVENT_PASSWORD_CHANGED => 'Senha trocada pela própria pessoa',
            self::EVENT_PASSWORD_CHANGE_FORCED => 'Troca de senha exigida no próximo acesso',
            self::EVENT_PASSWORD_RESET_REQUESTED => 'Link de redefinição de senha solicitado',
            self::EVENT_PASSWORD_RESET => 'Senha redefinida pelo link de e-mail',
            self::EVENT_SESSIONS_TERMINATED => 'Sessões encerradas',
            self::EVENT_LOGIN_BLOCKED => 'Login bloqueado por excesso de tentativas',
            self::EVENT_SELF_REGISTERED => 'Cadastro feito pela própria pessoa no site',
            self::EVENT_REGISTRATION_APPROVED => 'Cadastro aprovado',
            self::EVENT_REGISTRATION_REJECTED => 'Cadastro recusado',
            self::EVENT_APPROVAL_EMAILED => 'Aviso de aprovação enviado por e-mail',
            self::EVENT_API_TOKEN_ISSUED => 'Token da API emitido',
            self::EVENT_API_TOKEN_REVOKED => 'Token da API revogado',
            'health_consent_given' => 'Consentimento de dados de saúde registrado',
            'health_data_deleted' => 'Dados de saúde apagados pela própria pessoa',
            'consultations_deleted' => 'Consultas apagadas pela própria pessoa',
            'questions_deleted' => 'Perguntas apagadas pela própria pessoa',
            default => str($event)->headline()->toString(),
        };
    }

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
