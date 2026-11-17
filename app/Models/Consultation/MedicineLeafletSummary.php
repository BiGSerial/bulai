<?php

namespace App\Models\Consultation;

use App\Models\Knowledge\ActiveIngredient;
use App\Services\Audit\ReadabilityService;
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

    /** De quem é o resumo, para a tela: os princípios ativos da chave ou, na chave por produto ("p:dorflex"), o produto. */
    public function subjectLabel(): string
    {
        if (str_starts_with((string) $this->ingredient_key, 'p:')) {
            return mb_strtoupper(mb_substr((string) $this->ingredient_key, 2));
        }
        $names = ActiveIngredient::query()->whereIn('id', array_filter(array_map('intval', explode('-', (string) $this->ingredient_key))))
            ->orderBy('canonical_name')->pluck('canonical_name')->all();

        return $names !== [] ? implode(', ', $names) : 'Princípios ativos '.$this->ingredient_key;
    }

    /** @var array<string, mixed>|null */
    private ?array $readabilityCache = null;

    /**
     * Legibilidade (índice de Flesch adaptado, Martins et al., 1996) do resumo, de cada seção e dos trechos de origem, e se
     * atinge o alvo configurado. Calculada do texto gravado: vale também para bulas geradas antes da medição.
     *
     * @return array{summary: array<string, mixed>|null, sources: array<string, mixed>|null, sections: array<string, array<string, mixed>|null>, target: float, meets_target: bool|null}
     */
    public function readability(): array
    {
        if ($this->readabilityCache !== null) {
            return $this->readabilityCache;
        }
        $service = app(ReadabilityService::class);
        $sections = [];
        $all = [];
        foreach ($this->sections ?? [] as $key => $section) {
            $texts = $this->claimTexts($section);
            $all = [...$all, ...$texts];
            $sections[$key] = $texts === [] ? null : $service->flesch(implode(' ', $texts));
        }
        $sources = array_values(array_filter(array_map(fn ($evidence): string => is_array($evidence) ? (string) ($evidence['excerpt'] ?? '') : '', $this->evidences ?? [])));
        $summary = $all === [] ? null : $service->flesch(implode(' ', $all));
        $target = (float) config('bulai.leaflet.readability.min_flesch');

        return $this->readabilityCache = [
            'summary' => $summary,
            'sources' => $sources === [] ? null : $service->flesch(implode(' ', $sources)),
            'sections' => $sections,
            'target' => $target,
            'meets_target' => ($summary['index'] ?? null) === null ? null : $summary['index'] >= $target,
        ];
    }

    /**
     * Frases de uma seção, nos dois formatos gravados (lista de afirmações ou {label, claims}).
     *
     * @return list<string>
     */
    public function claimTexts(mixed $section): array
    {
        $claims = is_array($section) && array_is_list($section) ? $section : (is_array($section) ? ($section['claims'] ?? []) : []);

        return array_values(array_filter(array_map(fn ($claim): string => trim(is_array($claim) ? (string) ($claim['text'] ?? '') : (string) $claim), (array) $claims)));
    }
}
