<?php

namespace App\Services\Users;

use App\Models\User;
use App\Models\UserAuditEvent;
use App\UserRole;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gestão de acessos: cadastro com senha provisória, edição, ativação e troca de senha.
 *
 * Toda alteração grava um evento em user_audit_events (nunca com senha ou hash). As travas de
 * administrador rodam em transação com lock nas linhas de ADMIN ativos, para que duas ações
 * simultâneas não deixem o sistema sem nenhum administrador ativo.
 */
final class UserAccountService
{
    public const TEMPORARY_PASSWORD_LENGTH = 16;

    private const DUPLICATE_EMAIL_MESSAGE = 'Este e-mail já está cadastrado para outro usuário.';

    /**
     * Cadastra um acesso com senha provisória e troca obrigatória no primeiro login.
     *
     * @param  array{name: string, email: string, role: string}  $attributes
     * @return array{0: User, 1: string} usuário e senha provisória em texto puro (para exibir uma única vez)
     */
    public function create(array $attributes, User $actor): array
    {
        $email = $this->normalizeEmail($attributes['email']);
        $this->ensureEmailIsAvailable($email);
        $temporaryPassword = $this->generateTemporaryPassword();

        $user = $this->translateDuplicateEmail(fn (): User => DB::transaction(function () use ($attributes, $email, $temporaryPassword, $actor): User {
            $user = new User;
            $user->forceFill([
                'name' => Str::squish($attributes['name']),
                'email' => $email,
                'password' => $temporaryPassword,
                'role' => UserRole::from($attributes['role']),
                'active' => true,
                'must_change_password' => true,
                'created_by' => $actor->id,
            ])->save();

            $this->record($user, UserAuditEvent::EVENT_CREATED, $actor, ['email' => $email, 'role' => $user->role->value]);

            return $user;
        }));

        return [$user->refresh(), $temporaryPassword];
    }

    /**
     * Atualiza nome, e-mail e perfil. Trocar o perfil derruba as sessões da pessoa.
     *
     * @param  array{name: string, email: string, role: string}  $attributes
     */
    public function update(User $user, array $attributes, User $actor): User
    {
        $email = $this->normalizeEmail($attributes['email']);
        $this->ensureEmailIsAvailable($email, $user->id);

        return $this->translateDuplicateEmail(fn (): User => DB::transaction(function () use ($user, $attributes, $email, $actor): User {
            $activeAdministratorIds = $this->lockActiveAdministratorIds();
            $user = $this->lock($user);
            $role = UserRole::from($attributes['role']);
            $previousRole = $user->role;

            if ($previousRole === UserRole::Admin && $role !== UserRole::Admin) {
                if ($actor->is($user)) {
                    throw ValidationException::withMessages(['role' => 'Você não pode remover o seu próprio perfil de administrador.']);
                }

                $this->ensureAnotherActiveAdministrator($user, $activeAdministratorIds, 'role');
            }

            $values = ['name' => Str::squish($attributes['name']), 'email' => $email];
            $changes = [];
            foreach ($values as $field => $value) {
                if ($user->{$field} !== $value) {
                    $changes[$field] = ['from' => $user->{$field}, 'to' => $value];
                }
            }

            $user->forceFill([...$values, 'role' => $role])->save();

            if ($changes !== []) {
                $this->record($user, UserAuditEvent::EVENT_UPDATED, $actor, ['changes' => $changes]);
            }

            if ($previousRole !== $role) {
                $this->revokeAccess($user);
                $this->record($user, UserAuditEvent::EVENT_ROLE_CHANGED, $actor, ['from' => $previousRole->value, 'to' => $role->value]);
            }

            return $user->refresh();
        }));
    }

    /**
     * Ativa ou desativa o acesso. Desativar derruba as sessões da pessoa.
     */
    public function setActive(User $user, bool $active, User $actor): User
    {
        return DB::transaction(function () use ($user, $active, $actor): User {
            $activeAdministratorIds = $this->lockActiveAdministratorIds();
            $user = $this->lock($user);

            if ($user->isActive() === $active) {
                return $user;
            }

            if (! $active) {
                if ($actor->is($user)) {
                    throw ValidationException::withMessages(['active' => 'Você não pode desativar o seu próprio acesso.']);
                }

                if ($user->isAdmin()) {
                    $this->ensureAnotherActiveAdministrator($user, $activeAdministratorIds, 'active');
                }
            }

            $user->forceFill(['active' => $active])->save();

            if (! $active) {
                $this->revokeAccess($user);
            }

            $this->record($user, $active ? UserAuditEvent::EVENT_REACTIVATED : UserAuditEvent::EVENT_DEACTIVATED, $actor);

            return $user->refresh();
        });
    }

    /**
     * Gera uma nova senha provisória, exige a troca no próximo login e derruba as sessões da pessoa.
     *
     * @return string senha provisória em texto puro (para exibir uma única vez)
     */
    public function issueTemporaryPassword(User $user, User $actor): string
    {
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['temporaryPassword' => 'Para trocar a sua própria senha, use Minha conta.']);
        }

        $temporaryPassword = $this->generateTemporaryPassword();

        DB::transaction(function () use ($user, $temporaryPassword, $actor): void {
            $user = $this->lock($user);
            $user->forceFill(['password' => $temporaryPassword, 'must_change_password' => true])->save();
            $this->revokeAccess($user);
            $this->record($user, UserAuditEvent::EVENT_TEMPORARY_PASSWORD_ISSUED, $actor);
        });

        return $temporaryPassword;
    }

    /**
     * Troca de senha pela própria pessoa: limpa a troca obrigatória e derruba as outras sessões,
     * mantendo a sessão atual.
     */
    public function changePassword(User $user, string $newPassword, ?string $currentSessionId): void
    {
        $this->storeOwnPassword($user, $newPassword, $currentSessionId);
    }

    /**
     * Troca da senha provisória na tela de primeiro acesso: mesmo efeito de changePassword, com a
     * origem registrada na auditoria (details.via = first_access).
     */
    public function completeFirstAccess(User $user, string $newPassword, ?string $currentSessionId): void
    {
        $this->storeOwnPassword($user, $newPassword, $currentSessionId, ['via' => UserAuditEvent::VIA_FIRST_ACCESS]);
    }

    /** @param array<string, string> $details */
    private function storeOwnPassword(User $user, string $newPassword, ?string $currentSessionId, array $details = []): void
    {
        DB::transaction(function () use ($user, $newPassword, $currentSessionId, $details): void {
            $user = $this->lock($user);
            $user->forceFill(['password' => $newPassword, 'must_change_password' => false])->save();
            $this->revokeAccess($user, $currentSessionId);
            $this->record($user, UserAuditEvent::EVENT_PASSWORD_CHANGED, $user, $details);
        });
    }

    private function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function generateTemporaryPassword(): string
    {
        return Str::password(self::TEMPORARY_PASSWORD_LENGTH, symbols: false);
    }

    private function ensureEmailIsAvailable(string $email, ?int $ignoreUserId = null): void
    {
        $taken = User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->when($ignoreUserId !== null, fn ($query) => $query->whereKeyNot($ignoreUserId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['email' => self::DUPLICATE_EMAIL_MESSAGE]);
        }
    }

    /**
     * Converte a violação do índice único de e-mail (corrida entre duas gravações) em erro de validação.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function translateDuplicateEmail(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $exception) {
            if (in_array((string) ($exception->errorInfo[0] ?? ''), ['23505', '19'], true)) {
                throw ValidationException::withMessages(['email' => self::DUPLICATE_EMAIL_MESSAGE]);
            }

            throw $exception;
        }
    }

    /**
     * Trava as linhas de ADMIN ativos (sempre na mesma ordem, antes de qualquer outra linha, para evitar deadlock).
     *
     * @return array<int, int>
     */
    private function lockActiveAdministratorIds(): array
    {
        return User::query()
            ->where('role', UserRole::Admin)
            ->where('active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all();
    }

    /** @param array<int, int> $activeAdministratorIds */
    private function ensureAnotherActiveAdministrator(User $user, array $activeAdministratorIds, string $field): void
    {
        if (array_diff($activeAdministratorIds, [$user->id]) === []) {
            throw ValidationException::withMessages([$field => 'É preciso manter pelo menos um administrador ativo.']);
        }
    }

    private function lock(User $user): User
    {
        return User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Derruba o acesso: gira o remember_token (invalida o "Manter conectado") e apaga as sessões
     * gravadas no banco, exceto a indicada. Com outro driver de sessão, o middleware de usuário
     * ativo e o perfil relido a cada requisição continuam barrando o acesso.
     */
    private function revokeAccess(User $user, ?string $exceptSessionId = null): void
    {
        $user->setRememberToken(Str::random(60));
        $user->save();

        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();
    }

    /** @param array<string, mixed> $details */
    private function record(User $user, string $event, ?User $actor, array $details = []): void
    {
        UserAuditEvent::query()->create([
            'user_id' => $user->id,
            'actor_id' => $actor?->id,
            'event' => $event,
            'details' => $details,
            'ip' => request()->ip(),
        ]);
    }
}
