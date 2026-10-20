<?php

namespace App\Services\Knowledge;

use RuntimeException;

/** A mensagem é sempre um código controlado: nunca contém URL, token ou conteúdo do documento. */
final class DocumentOcrException extends RuntimeException
{
    public const DISABLED = 'OCR_DISABLED';

    public const NOT_CONFIGURED = 'OCR_NOT_CONFIGURED';

    public const INVALID_FILE = 'OCR_INVALID_FILE';

    public const PAYLOAD_TOO_LARGE = 'OCR_PAYLOAD_TOO_LARGE';

    public const UNAVAILABLE = 'OCR_UNAVAILABLE';

    public const TIMEOUT = 'OCR_TIMEOUT';

    public const RATE_LIMITED = 'OCR_RATE_LIMITED';

    public const REJECTED = 'OCR_REJECTED';

    public const INVALID_RESPONSE = 'OCR_INVALID_RESPONSE';

    public function __construct(public readonly string $errorCode, public readonly ?string $correlationId = null)
    {
        parent::__construct($errorCode);
    }
}
