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
            return DB::transaction(fn (): mixed => $section());
        } catch (Throwable $exception) {
            report($exception);

            return self::failure($exception);
        }
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
