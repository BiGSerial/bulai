<?php

namespace App\Data\Ai;

final readonly class VisionImage
{
    public const ALLOWED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(
        public string $mimeType,
        public string $contents,
    ) {}
}
