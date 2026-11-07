<?php

namespace App\Jobs;

use App\Services\Knowledge\ActiveIngredientCatalogBuilder;
use App\Services\Knowledge\KnowledgeDocumentIngredientLinker;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Constrói o catálogo de princípios ativos (DDInter → aliases → medicamentos da ANVISA → interações) e revincula as bulas
 * prontas. Enfileirado automaticamente ao fim de cada importação do catálogo ANVISA e do DDInter, para que o Perguntar e as
 * interações não dependam de alguém lembrar de rodar `knowledge:build-ingredients`. O mesmo que o comando faz.
 */
class BuildActiveIngredientCatalog implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    public int $uniqueFor = 1800;

    public function __construct(public readonly string $trigger = 'manual')
    {
        $this->timeout = (int) config('knowledge.ingredients.job_timeout_seconds');
    }

    public function handle(ActiveIngredientCatalogBuilder $builder, KnowledgeDocumentIngredientLinker $linker): void
    {
        $stats = $builder->build();
        $translation = $builder->translateUnresolved();
        $documents = $linker->linkAll();
        Log::info('active_ingredient_catalog_built', ['trigger' => $this->trigger, 'stats' => array_diff_key($stats, ['curated_skipped' => true]), 'translation' => array_diff_key($translation, ['unknown' => true]), 'documents' => $documents]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('active_ingredient_catalog_build_failed', ['trigger' => $this->trigger, 'exception' => $exception]);
    }

    public function uniqueId(): string
    {
        return 'build-active-ingredient-catalog';
    }
}
