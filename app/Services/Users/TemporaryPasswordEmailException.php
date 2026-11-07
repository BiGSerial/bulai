<?php

namespace App\Services\Users;

use RuntimeException;
use Throwable;

/**
 * O e-mail com a senha provisória não pôde ser entregue ao transporte (API da KingHost recusou, sem token, sem conexão).
 * A senha já foi gerada e continua válida: a tela a exibe uma vez e explica o motivo, em vez de responder 500.
 */
final class TemporaryPasswordEmailException extends RuntimeException
{
    public static function from(Throwable $cause): self
    {
        return new self(
            'A senha provisória foi gerada e aparece abaixo, mas o e-mail não pôde ser enviado: '.$cause->getMessage()
            .' Informe a senha por outro canal ou corrija o envio de e-mail e gere outra.',
            0,
            $cause,
        );
    }
}
