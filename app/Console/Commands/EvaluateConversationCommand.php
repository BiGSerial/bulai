<?php

namespace App\Console\Commands;

use App\Services\Evaluation\ConversationEvaluator;
use App\Services\Evaluation\EvaluationAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('evaluation:conversation {--medicine=ibuprofeno : Remédio dos cenários} {--other=sertralina : Remédio informado no cenário de contexto} {--second=paracetamol : Remédio do cenário de acompanhamento} {--label=run : Nome do resultado} {--score= : Só pontua um JSON já gerado (caminho), sem chamar o modelo}')]
#[Description('Avalia o Perguntar com o fluxo completo (modelo real) em oito cenários e pontua relevância, ancoragem, segurança, clareza e naturalidade')]
class EvaluateConversationCommand extends Command
{
    public function handle(ConversationEvaluator $evaluator, EvaluationAccount $account): int
    {
        if ($this->option('score')) {
            $turns = json_decode((string) File::get((string) $this->option('score')), true) ?? [];
            $turns = array_map(function (array $turn) use ($evaluator): array {
                $turn['expects'] ??= $this->expectationsFor((string) ($turn['scenario'] ?? ''), $evaluator);

                return $turn;
            }, $turns);
        } else {
            $turns = $evaluator->run($account->get(), $evaluator->scenarios((string) $this->option('medicine'), (string) $this->option('other'), (string) $this->option('second')));
        }

        $scored = array_map(fn (array $turn): array => $turn + ['score' => $evaluator->score($turn)], $turns);
        $rows = array_map(fn (array $t): array => [
            $t['scenario'], mb_strimwidth((string) $t['question'], 0, 38, '…'), $t['status'] ?? 'erro', $t['coverage'] ?? '—',
            ...array_map(fn (string $d): string => match ($t['score']['scores'][$d]) {
                true => 'ok', false => 'FALHA', default => '—'
            }, ConversationEvaluator::DIMENSIONS),
            implode('; ', $t['score']['notes']),
        ], $scored);
        $this->table(['Cenário', 'Pergunta', 'Situação', 'Cobertura', ...ConversationEvaluator::DIMENSIONS, 'Observações'], $rows);
        foreach ($evaluator->summarize($scored) as $dimension => $counts) {
            $this->line(sprintf('%-13s %d/%d', $dimension, $counts['passed'], $counts['total']));
        }

        $directory = storage_path('app/evaluation/conversation');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $this->option('label')).'.json';
        File::put($path, json_encode(['generated_at' => now()->toIso8601String(), 'summary' => $evaluator->summarize($scored), 'turns' => $scored], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->components->info('Resultado gravado em '.str_replace(base_path().'/', '', $path));

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function expectationsFor(string $scenario, ConversationEvaluator $evaluator): array
    {
        foreach ($evaluator->scenarios('x', 'y', 'z') as $name => $definition) {
            if (str_starts_with($name, substr($scenario, 0, 2))) {
                return $definition['expects'];
            }
        }

        return [];
    }
}
