<?php

namespace App\Console\Commands;

use App\Services\Knowledge\ActiveIngredientCatalogBuilder;
use App\Services\Knowledge\KnowledgeDocumentIngredientLinker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('knowledge:build-ingredients {--aliases= : CSV de aliases curados (padrão: database/data/ingredient_aliases.csv)}')]
#[Description('Constrói o catálogo de princípios ativos (DDInter), liga medicamentos da ANVISA e interações a ele')]
class BuildActiveIngredientCatalogCommand extends Command
{
    public function handle(ActiveIngredientCatalogBuilder $builder, KnowledgeDocumentIngredientLinker $linker): int
    {
        $stats = $builder->build($this->option('aliases') ?: null);
        $documentLinks = $linker->linkAll();
        $skipped = $stats['curated_skipped'];
        unset($stats['curated_skipped']);

        $this->table(['Métrica', 'Valor'], collect($stats)->map(fn (int $value, string $key): array => [$key, $value])->values()->all());
        $this->table(['Documentos', 'Com ingrediente', 'Ligações'], [array_values($documentLinks)]);
        if ($skipped !== []) {
            $this->components->warn('Aliases curados ignorados (alvo inexistente no DDInter): '.implode('; ', $skipped));
        }

        return self::SUCCESS;
    }
}
