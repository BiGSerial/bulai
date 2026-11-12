<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Isola um bloco da auditoria: se ele falhar (consulta que o banco daquele ambiente recusa, dado fora do formato esperado),
 * o relatório sai com o motivo naquele bloco em vez de a resposta inteira virar erro 500. A exceção também vai para o log.
 * Cada bloco roda num savepoint: no PostgreSQL, uma consulta recusada aborta a transação e derrubaria os blocos seguintes.
 */
final class AuditSection
{
    /**
     * @template T
     *
     * @param  callable(): T  $section
     * @return T|array{error: array{exception: string, message: string}}
     */
    public static function run(callable $section): mixed
    {
        try {
            return self::attempt($section);
        } catch (Throwable $exception) {
            report($exception);
            self::recoverConnection();

            return self::failure($exception);
        }
    }

    /**
     * Conexão ociosa derrubada pelo servidor (visto em produção: a primeira consulta depois de um intervalo falhava com
     * "no connection to the server"): reabre e tenta o bloco mais uma vez antes de desistir.
     */
    private static function attempt(callable $section): mixed
    {
        try {
            return DB::transaction(fn (): mixed => $section());
        } catch (Throwable $exception) {
            if (! self::isLostConnection($exception)) {
                throw $exception;
            }
            self::recoverConnection();

            return DB::transaction(fn (): mixed => $section());
        }
    }

    private static function isLostConnection(Throwable $exception): bool
    {
        return Str::contains($exception->getMessage(), ['no connection to the server', 'server closed the connection', 'terminating connection', 'SSL connection has been closed', 'Lost connection']);
    }

    /**
     * Se a conexão caiu no meio do bloco, o PDO continua marcado como "em transação" e o próximo bloco falharia com
     * "There is already an active transaction". Desfaz a transação pendente ou, se não der, reabre a conexão.
     * Só age quando nenhuma transação externa está aberta (nesse caso quem a abriu cuida dela).
     */
    private static function recoverConnection(): void
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() > 0) {
            return;
        }

        try {
            $pdo = $connection->getPdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable) {
            // a conexão não responde: a reabertura abaixo resolve
        }
        DB::reconnect();
    }

    /** @return array{error: array{exception: string, message: string}} */
    public static function failure(Throwable $exception): array
    {
        return ['error' => ['exception' => class_basename($exception), 'message' => Str::limit($exception->getMessage(), 500)]];
    }

    public static function failed(mixed $result): bool
    {
        return is_array($result) && array_key_exists('error', $result) && count($result) === 1;
    }
}
