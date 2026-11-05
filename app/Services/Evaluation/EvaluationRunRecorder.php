<?php

namespace App\Services\Evaluation;

use App\Services\Interactions\ExplanationValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Registra cada execução com tudo o que é preciso para reproduzi-la: código, dataset, documentos, modelos, parâmetros
 * e configuração. Os arquivos ficam em storage/app/evaluation/runs e nunca misturam configurações diferentes.
 */
final class EvaluationRunRecorder
{
    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function begin(string $task, string $datasetPath, array $parameters): array
    {
        return [
            'run_id' => (string) Str::uuid(),
            'task' => $task,
            'started_at' => now()->toIso8601String(),
            'code' => ['version' => config('bulai.code_version') ?: $this->gitRevision(), 'dirty' => $this->gitDirty()],
            'dataset' => ['path' => str_replace(base_path().'/', '', $datasetPath), 'sha256' => is_file($datasetPath) ? hash_file('sha256', $datasetPath) : null],
            'documents' => $this->documentsVersion(),
            'interaction_sources' => DB::table('drug_interaction_imports')->where('status', 'COMPLETED')->orderBy('original_filename')->pluck('sha256', 'original_filename')->all(),
            'models' => [
                'embedding' => ['driver' => config('ai.embedding.default'), 'indexed_models' => DB::table('knowledge_chunks')->whereNotNull('embedding_model')->distinct()->pluck('embedding_model')->all()],
                'generation' => ['driver' => config('ai.text.default'), 'model' => config('ai.text.drivers.'.config('ai.text.default').'.model')],
            ],
            'parameters' => $parameters,
            'configuration' => [
                'retrieval' => config('bulai.retrieval'),
                'generation' => config('bulai.generation'),
                'validator_min_grounding_overlap' => ExplanationValidator::MIN_GROUNDING_OVERLAP,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $run  retorno de begin()
     * @param  array<string, mixed>  $results
     */
    public function finish(array $run, array $results, string $label): string
    {
        $run['finished_at'] = now()->toIso8601String();
        $run['results'] = $results;
        $directory = storage_path('app/evaluation/runs');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.now()->format('Ymd-His').'-'.Str::slug($label).'-'.substr($run['run_id'], 0, 8).'.json';
        File::put($path, json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /** @return array{count: int, sha256: string|null} */
    private function documentsVersion(): array
    {
        $rows = DB::table('knowledge_documents')->where('content_status', 'READY')->orderBy('id')->get(['id', 'content_hash', 'sha256']);

        return ['count' => $rows->count(), 'sha256' => $rows->isEmpty() ? null : hash('sha256', $rows->map(fn (object $row): string => $row->id.':'.($row->content_hash ?? $row->sha256))->join('|'))];
    }

    private function gitRevision(): ?string
    {
        $revision = @shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse HEAD 2>/dev/null');

        return is_string($revision) && trim($revision) !== '' ? trim($revision) : null;
    }

    private function gitDirty(): ?bool
    {
        $status = @shell_exec('git -C '.escapeshellarg(base_path()).' status --porcelain --untracked-files=no 2>/dev/null');

        return is_string($status) ? trim($status) !== '' : null;
    }
}
