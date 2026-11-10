<?php

namespace App\Jobs;

use App\Models\Knowledge\ActiveIngredient;
use App\Services\Knowledge\External\ExternalLabelImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Importa (ou atualiza) a bula de prescrição da FDA de um princípio ativo pelo openFDA e traduz os trechos. Disparado pelo
 * comando `knowledge:import-fda-labels` e pelo Perguntar/consulta de interações quando falta a bula estrangeira de um
 * princípio ativo (fica pronta para a próxima pergunta).
 */
class ImportExternalLabel implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $ingredientId, public readonly bool $translate = true) {}

    public function uniqueId(): string
    {
        return (string) $this->ingredientId;
    }

    public function handle(ExternalLabelImporter $importer): void
    {
        $ingredient = ActiveIngredient::query()->find($this->ingredientId);
        if ($ingredient !== null) {
            $importer->import($ingredient, $this->translate);
        }
    }
}
