<?php

namespace App\Console\Commands;

use App\Services\Evaluation\DatasetBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('evaluation:build-datasets {--seed=database/evaluation/identification.seed.json} {--output=database/evaluation} {--name=v1 : Sufixo do arquivo} {--identification-only : Gera só o dataset de identificação}')]
#[Description('Gera os datasets de avaliação (identificação e interações) a partir do gabarito manual')]
class EvaluationBuildDatasetsCommand extends Command
{
    public function handle(DatasetBuilder $builder): int
    {
        $summary = $builder->build(base_path($this->option('seed')), base_path($this->option('output')), (string) $this->option('name'), ! $this->option('identification-only'));

        $this->table(['Métrica', 'Valor'], [
            ['Itens de identificação', $summary['identification']], ['Pares de interação', $summary['interactions']],
            ['Positivos (DDInter)', $summary['positives']], ['Negativos (ausentes no DDInter)', $summary['negatives']],
        ]);
        $this->components->warn('Fora do catálogo: '.($summary['dropped'] === [] ? 'nenhum' : implode(', ', $summary['dropped'])));
        $this->components->warn('Fora dos pares de interação (ingrediente ausente no DDInter): '.($summary['excluded_unreferenced'] === [] ? 'nenhum' : implode('; ', $summary['excluded_unreferenced'])));

        return self::SUCCESS;
    }
}
