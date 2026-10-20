<?php

namespace App\Services\Ai;

use RuntimeException;

/** A mensagem é sempre um código controlado: nunca contém URL, API key, prompt ou resposta do provider. */
final class AiProviderException extends RuntimeException
{
    public const NOT_CONFIGURED = 'AI_NOT_CONFIGURED';

    public const INVALID_REQUEST = 'AI_INVALID_REQUEST';

    public const AUTH_FAILED = 'AI_AUTH_FAILED';

    public const RATE_LIMITED = 'AI_RATE_LIMITED';

    public const TIMEOUT = 'AI_TIMEOUT';

    public const UNAVAILABLE = 'AI_UNAVAILABLE';

    public const REJECTED = 'AI_REJECTED';

    public const INVALID_RESPONSE = 'AI_INVALID_RESPONSE';

    public function __construct(public readonly string $errorCode, public readonly ?string $correlationId = null)
    {
        parent::__construct($errorCode);
    }
}
