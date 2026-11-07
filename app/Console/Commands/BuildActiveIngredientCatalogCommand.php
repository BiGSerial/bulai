<?php

namespace App\Console\Commands;

use App\Services\Knowledge\ActiveIngredientCatalogBuilder;
use App\Services\Knowledge\KnowledgeDocumentIngredientLinker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('knowledge:build-ingredients {--aliases= : CSV de aliases curados (padrão: database/data/ingredient_aliases.csv)} {--no-ai : Não traduzir com IA os nomes que aliases e regras não resolveram}')]
#[Description('Constrói o catálogo de princípios ativos (DDInter), liga medicamentos da ANVISA e interações a ele; traduz com IA o que sobrar')]
class BuildActiveIngredientCatalogCommand extends Command
{
    public function handle(ActiveIngredientCatalogBuilder $builder, KnowledgeDocumentIngredientLinker $linker): int
    {
        $stats = $builder->build($this->option('aliases') ?: null);
        $translation = $this->option('no-ai') ? null : $builder->translateUnresolved();
        $documentLinks = $linker->linkAll();
        $skipped = $stats['curated_skipped'];
        unset($stats['curated_skipped']);

        $this->table(['Métrica', 'Valor'], collect($stats)->map(fn (int $value, string $key): array => [$key, $value])->values()->all());
        if ($translation !== null) {
            $unknown = $translation['unknown'];
            unset($translation['unknown']);
            $this->table(['Tradução por IA', 'Valor'], collect($translation)->map(fn ($value, string $key): array => [$key, is_array($value) ? json_encode($value) : (string) $value])->values()->all());
            if ($unknown !== []) {
                $this->components->warn('Sem correspondência no DDInter (não ligados): '.implode('; ', array_slice($unknown, 0, 40)).(count($unknown) > 40 ? '…' : ''));
            }
        }
        $this->table(['Documentos', 'Com ingrediente', 'Ligações'], [array_values($documentLinks)]);
        if ($skipped !== []) {
            $this->components->warn('Aliases curados ignorados (alvo inexistente no DDInter): '.implode('; ', $skipped));
        }

        return self::SUCCESS;
    }
}
