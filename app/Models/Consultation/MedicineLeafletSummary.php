<?php

namespace App\Models\Consultation;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * "Bula humanizada": resumo em linguagem simples gerado a partir das bulas de um conjunto de princípios ativos,
 * com cada afirmação ligada aos trechos que a sustentam. Compartilhado entre produtos equivalentes e entre usuários.
 */
#[Fillable([
    'uuid', 'ingredient_key', 'prompt_version', 'documents_fingerprint', 'status', 'sections', 'evidences', 'generation',
    'retrieval', 'pipeline', 'duration_ms', 'views',
])]
class MedicineLeafletSummary extends Model
{
    public const STATUS_GENERATED = 'generated';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_UNAVAILABLE = 'unavailable';

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'evidences' => 'array',
            'generation' => 'array',
            'retrieval' => 'array',
            'pipeline' => 'array',
            'views' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @param list<int> $ingredientIds */
    public static function keyFor(array $ingredientIds): string
    {
        $ids = array_values(array_unique(array_map('intval', $ingredientIds)));
        sort($ids);

        return implode('-', $ids);
    }
}
