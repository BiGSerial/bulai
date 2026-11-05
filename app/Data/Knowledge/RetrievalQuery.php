<?php

namespace App\Data\Knowledge;

final readonly class RetrievalQuery
{
    public const LEXICAL = 'lexical';

    public const VECTOR = 'vector';

    public const HYBRID = 'hybrid';

    /**
     * @param  list<int>|null  $documentIds  restringe a estes documentos
     * @param  list<string>|null  $sectionPatterns  trechos de nome de seção (comparação sem caixa), ex.: ['INTERA']
     */
    public function __construct(
        public string $text,
        public string $mode = self::LEXICAL,
        public int $limit = 5,
        public ?array $documentIds = null,
        public ?array $sectionPatterns = null,
        public ?string $language = null,
    ) {}
}
