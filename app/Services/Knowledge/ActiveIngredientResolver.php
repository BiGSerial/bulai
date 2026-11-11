<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\IngredientResolution;
use App\Models\Knowledge\ActiveIngredientAlias;

/**
 * Resolve um nome de princípio ativo (PT ou EN) para um único `active_ingredients.id`.
 * Nomes que apontam para mais de um princípio ativo são "ambíguos" e nunca são ligados: errar uma ligação
 * produziria interações falsas, enquanto não ligar apenas reduz a cobertura.
 */
final class ActiveIngredientResolver
{
    public const METHOD_ALIAS = 'alias';

    public const METHOD_SALT_STRIPPED = 'salt_stripped';

    public const METHOD_COGNATE_RULE = 'cognate_rule';

    /** @var array<string, list<int>>|null */
    private ?array $index = null;

    public function __construct(
        private readonly IngredientNameNormalizer $normalizer,
        private readonly IngredientCognateGenerator $cognates,
    ) {}

    public function flush(): void
    {
        $this->index = null;
    }

    /** Resolução em tempo de consulta: apenas aliases já gravados (sem gerar candidatos). */
    public function resolve(string $name): IngredientResolution
    {
        return $this->resolveWith($name, false)[0];
    }

    /**
     * Resolução usada na construção do catálogo; devolve também o alias encontrado quando veio de regra cognata.
     *
     * @return array{0: IngredientResolution, 1: string|null} resolução e alias normalizado a persistir
     */
    public function resolveWithCognates(string $name, ?int $excluding = null): array
    {
        return $this->resolveWith($name, true, $excluding);
    }

    /**
     * Resolve com regras cognatas e grava o alias aprendido (`pending_review`), para que a próxima consulta o encontre
     * direto. É o que impede "ibuprofeno" de virar um princípio ativo novo ao lado de "ibuprofen".
     */
    public function resolveAndLearn(string $name): IngredientResolution
    {
        [$resolution, $learnedAlias] = $this->resolveWithCognates($name);
        if ($resolution->method === self::METHOD_COGNATE_RULE && $learnedAlias !== null) {
            ActiveIngredientAlias::query()->firstOrCreate(
                ['ingredient_id' => $resolution->ingredientId, 'normalized_alias' => $learnedAlias],
                ['alias' => $learnedAlias, 'language' => 'pt', 'origin' => 'rule', 'review_status' => 'pending_review'],
            );
            $this->flush();
        }

        return $resolution;
    }

    /**
     * @param  int|null  $excluding  princípio ativo ignorado (ex.: o próprio duplicado que se quer fundir)
     * @return array{0: IngredientResolution, 1: string|null}
     */
    private function resolveWith(string $name, bool $allowCognates, ?int $excluding = null): array
    {
        $full = $this->normalizer->normalize($name);
        if ($full === '') {
            return [new IngredientResolution(IngredientResolution::UNRESOLVED), null];
        }

        $attempts = [[$full, self::METHOD_ALIAS, 1.0]];
        $base = $this->normalizer->base($name);
        if ($base !== $full) {
            $attempts[] = [$base, self::METHOD_SALT_STRIPPED, 0.9];
        }

        $sawAmbiguity = false;
        foreach ($attempts as [$key, $method, $confidence]) {
            $ids = $this->ids($key, $excluding);
            if (count($ids) === 1) {
                return [new IngredientResolution(IngredientResolution::RESOLVED, $ids[0], $method, $confidence), null];
            }
            $sawAmbiguity = $sawAmbiguity || count($ids) > 1;
        }

        if ($allowCognates && ! $sawAmbiguity) {
            foreach ($this->cognates->candidates($base) as $candidate) {
                $ids = $this->ids($candidate, $excluding);
                if (count($ids) === 1) {
                    return [new IngredientResolution(IngredientResolution::RESOLVED, $ids[0], self::METHOD_COGNATE_RULE, 0.8), $base];
                }
                $sawAmbiguity = $sawAmbiguity || count($ids) > 1;
            }
        }

        return [new IngredientResolution($sawAmbiguity ? IngredientResolution::AMBIGUOUS : IngredientResolution::UNRESOLVED), null];
    }

    /** @return list<int> */
    private function ids(string $normalizedAlias, ?int $excluding = null): array
    {
        $this->index ??= $this->loadIndex();
        $ids = $this->index[$normalizedAlias] ?? [];

        return $excluding === null ? $ids : array_values(array_filter($ids, fn (int $id): bool => $id !== $excluding));
    }

    /** @return array<string, list<int>> */
    private function loadIndex(): array
    {
        $index = [];
        ActiveIngredientAlias::query()->select(['normalized_alias', 'ingredient_id'])->cursor()->each(function (ActiveIngredientAlias $alias) use (&$index): void {
            $index[$alias->normalized_alias][$alias->ingredient_id] = $alias->ingredient_id;
        });

        return array_map(fn (array $ids): array => array_values($ids), $index);
    }
}
