<?php

namespace App\Console\Commands;

use App\Services\Evaluation\BaselineEvaluator;
use App\Services\Evaluation\EvaluationRunRecorder;
use App\Services\Evaluation\GenerationEvaluator;
use App\Services\Evaluation\IdentificationEvaluator;
use App\Services\Evaluation\InteractionEvaluator;
use App\Services\Evaluation\RetrievalEvaluator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('evaluation:run {task : identification|interactions|retrieval|generation|baseline} {--dataset= : Caminho do dataset (padrão por tarefa)} {--mode=lexical : Recuperação: lexical|vector|hybrid (retrieval aceita lista separada por vírgula)} {--limit= : Máximo de itens} {--seed=42 : Semente da amostragem (baseline)} {--label= : Rótulo da execução}')]
#[Description('Executa uma avaliação reproduzível e grava o registro em storage/app/evaluation/runs')]
class EvaluationRunCommand extends Command
{
    private const DEFAULT_DATASETS = [
        'identification' => 'database/evaluation/identification.v1.json',
        'interactions' => 'database/evaluation/interactions.v1.json',
        'generation' => 'database/evaluation/interactions.v1.json',
        'baseline' => 'database/evaluation/interactions.v1.json',
        'retrieval' => 'database/evaluation/retrieval.v1.json',
    ];

    public function handle(EvaluationRunRecorder $recorder): int
    {
        $task = (string) $this->argument('task');
        if (! isset(self::DEFAULT_DATASETS[$task])) {
            $this->components->error('Tarefa desconhecida: '.$task);

            return self::FAILURE;
        }

        $dataset = base_path($this->option('dataset') ?: self::DEFAULT_DATASETS[$task]);
        if (! is_file($dataset)) {
            $this->components->error('Dataset não encontrado: '.$dataset.' (rode evaluation:build-datasets).');

            return self::FAILURE;
        }

        $mode = (string) $this->option('mode');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $run = $recorder->begin($task, $dataset, ['mode' => $mode, 'limit' => $limit, 'seed' => (int) $this->option('seed')]);

        $results = match ($task) {
            'identification' => app(IdentificationEvaluator::class)->run($dataset),
            'interactions' => app(InteractionEvaluator::class)->run($dataset, $mode, $limit),
            'generation' => app(GenerationEvaluator::class)->run($dataset, $mode, $limit, (int) $this->option('seed')),
            'baseline' => app(BaselineEvaluator::class)->run($dataset, base_path(self::DEFAULT_DATASETS['identification']), $limit, (int) $this->option('seed')),
            'retrieval' => app(RetrievalEvaluator::class)->run($dataset, array_map('trim', explode(',', $mode))),
        };

        $path = $recorder->finish($run, $results, $this->option('label') ?: $task.'-'.$mode);
        $this->line((string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->components->info('Registro: '.$path);

        return self::SUCCESS;
    }
}
