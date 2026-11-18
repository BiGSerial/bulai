<?php

namespace App\Services\Interactions;

use RuntimeException;

/** Pedido de consulta de interações recusado (poucos remédios, limite por hora, outra consulta começando). */
final class InteractionRequestException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $limited = false)
    {
        parent::__construct($message);
    }
}
