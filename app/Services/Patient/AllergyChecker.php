<?php

namespace App\Services\Patient;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientAllergy;
use App\Services\Knowledge\ActiveIngredientResolver;
use App\Services\Knowledge\IngredientNameNormalizer;
use Illuminate\Support\Collection;

/**
 * Cruza as alergias registradas pelo paciente com os princípios ativos de um medicamento: por nome (PT/EN, sem sais) e por
 * classe farmacológica (lista curada em database/data/ingredient_classes.csv, pendente de revisão farmacêutica). Reação
 * cruzada entre classes diferentes NÃO é modelada.
 */
final class AllergyChecker
{
    private const CLASS_ALIASES = [
        'sulfa' => 'sulfonamida', 'antiinflamatorio' => 'aine', 'anti inflamatorio' => 'aine', 'anti inflamatorio nao esteroidal' => 'aine',
        'anti inflamatorio nao esteroide' => 'aine', 'benzodiazepina' => 'benzodiazepinico', 'betalactamico' => 'penicilina',
    ];

    /** @var array<string, list<string>>|null */
    private ?array $classes = null;

    public function __construct(
        private readonly IngredientNameNormalizer $normalizer,
        private readonly ActiveIngredientResolver $resolver,
    ) {}

    /**
     * @param  Collection<int, PatientAllergy>  $allergies
     * @return list<array{allergy: string, ingredient: string, via: string}>
     */
    public function check(Collection $allergies, AnvisaMedicine $medicine): array
    {
        $components = $this->components($medicine);
        $matches = [];
        foreach ($allergies as $allergy) {
            $base = $this->normalizer->base($allergy->substance);
            $class = $this->classFor($base);
            foreach ($components as $component) {
                $via = match (true) {
                    $component === $base || $this->sameIngredient($allergy->substance, $component) => 'ingrediente',
                    $class !== null && in_array($component, $this->classMembers($class), true) => 'classe',
                    default => null,
                };
                if ($via !== null) {
                    $matches[] = ['allergy' => $allergy->substance, 'ingredient' => $component, 'via' => $via];

                    break;
                }
            }
        }

        return $matches;
    }

    /** @return list<string> */
    private function components(AnvisaMedicine $medicine): array
    {
        $texts = filled($medicine->active_ingredient) ? $this->normalizer->components($medicine->active_ingredient) : [$medicine->product_name];

        return array_values(array_unique(array_map(fn (string $text): string => $this->normalizer->base($text), $texts)));
    }

    private function sameIngredient(string $allergy, string $component): bool
    {
        $a = $this->resolver->resolve($allergy);

        return $a->isResolved() && $a->ingredientId === $this->resolver->resolve($component)->ingredientId;
    }

    private function classFor(string $base): ?string
    {
        $key = self::CLASS_ALIASES[$base] ?? (str_ends_with($base, 's') ? rtrim($base, 's') : $base);
        $key = self::CLASS_ALIASES[$key] ?? $key;

        return isset($this->loadClasses()[$key]) ? $key : null;
    }

    /** @return list<string> */
    private function classMembers(string $class): array
    {
        return $this->loadClasses()[$class] ?? [];
    }

    /** @return array<string, list<string>> */
    private function loadClasses(): array
    {
        if ($this->classes === null) {
            $this->classes = [];
            $handle = fopen(database_path('data/ingredient_classes.csv'), 'rb');
            fgetcsv($handle, escape: '');
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                $this->classes[$row[0]][] = $this->normalizer->base($row[1]);
            }
            fclose($handle);
        }

        return $this->classes;
    }
}
