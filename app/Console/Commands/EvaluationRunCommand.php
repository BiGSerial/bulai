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

#[Signature('evaluation:run {task : identification|interactions|retrieval|generation|baseline} {--dataset= : Caminho do dataset (padrão por tarefa)} {--mode=lexical : Recuperação: lexical|vector|hybrid (retrieval aceita lista separada por vírgula)} {--limit= : Máximo de itens} {--seed=42 : Semente da amostragem (baseline)} {--label= : Rótulo da execução} {--provider= : Driver de texto para esta execução (ex.: anthropic, local); padrão AI_TEXT_DRIVER} {--pairs-from= : generation: repete exatamente os pares de um registro anterior (comparação controlada entre modelos)}')]
#[Description('Executa uma avaliação reproduzível e grava o registro em storage/app/evaluation/runs')]
class EvaluationRunCommand extends Command
{
    private const DEFAULT_DATASETS = [
        'identification' => 'database/evaluation/identification.v2.json',
        'interactions' => 'database/evaluation/interactions.v2.json',
        'generation' => 'database/evaluation/interactions.v2.json',
        'baseline' => 'database/evaluation/interactions.v2.json',
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

        if ($this->option('provider')) {
            config(['ai.text.default' => (string) $this->option('provider')]);
        }
        $reference = null;
        if ($task === 'generation' && $this->option('pairs-from')) {
            $record = json_decode((string) @file_get_contents(base_path((string) $this->option('pairs-from'))), true);
            $reference = is_array($record['results']['pairs'] ?? null) ? $record['results']['pairs'] : null;
            if ($reference === null) {
                $this->components->error('O registro não tem a lista de pares (results.pairs): '.$this->option('pairs-from'));

                return self::FAILURE;
            }
        }
        $mode = (string) $this->option('mode');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $run = $recorder->begin($task, $dataset, ['mode' => $mode, 'limit' => $limit, 'seed' => (int) $this->option('seed'), 'provider' => $this->option('provider'), 'pairs_from' => $this->option('pairs-from')]);

        $results = match ($task) {
            'identification' => app(IdentificationEvaluator::class)->run($dataset),
            'interactions' => app(InteractionEvaluator::class)->run($dataset, $mode, $limit),
            'generation' => app(GenerationEvaluator::class)->run($dataset, $mode, $limit, (int) $this->option('seed'), $reference),
            'baseline' => app(BaselineEvaluator::class)->run($dataset, base_path(self::DEFAULT_DATASETS['identification']), $limit, (int) $this->option('seed')),
            'retrieval' => app(RetrievalEvaluator::class)->run($dataset, array_map('trim', explode(',', $mode))),
        };

        $path = $recorder->finish($run, $results, $this->option('label') ?: $task.'-'.$mode);
        $this->line((string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->components->info('Registro: '.$path);

        return self::SUCCESS;
    }
}
