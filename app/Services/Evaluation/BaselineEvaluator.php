<?php

namespace App\Services\Evaluation;

use App\Data\Ai\TextGenerationRequest;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use Illuminate\Support\Facades\File;

/**
 * Baseline SEM RAG e sem base estruturada: o mesmo modelo de geração responde, só com o conhecimento paramétrico, se há
 * interação entre dois princípios ativos. Serve para comparar com o pipeline, não para uso no produto.
 */
final class BaselineEvaluator
{
    /** Folga para o raciocínio adaptativo do modelo: com 60 tokens a resposta vinha só com o bloco de raciocínio (AI_INVALID_RESPONSE). */
    private const MAX_OUTPUT_TOKENS = 1024;

    public function __construct(private readonly AiProviderManager $providers) {}

    /** @return array<string, mixed> */
    public function run(string $interactionsPath, string $identificationPath, ?int $limit, int $seed = 42): array
    {
        $dataset = json_decode((string) File::get($interactionsPath), true, flags: JSON_THROW_ON_ERROR);
        $expected = collect(json_decode((string) File::get($identificationPath), true, flags: JSON_THROW_ON_ERROR)['items'])->keyBy('registration_number');
        $binary = ['tp' => 0, 'fp' => 0, 'tn' => 0, 'fn' => 0];
        $abstained = 0;
        $errors = 0;
        $severityPairs = [];
        $pairs = [];
        $items = Sampling::seeded($dataset['items'], $limit, $seed);

        foreach ($items as $item) {
            $a = implode(' + ', $expected[$item['a_registration']]['expected_ingredients']);
            $b = implode(' + ', $expected[$item['b_registration']]['expected_ingredients']);
            $pair = ['a_name' => $item['a_name'] ?? null, 'b_name' => $item['b_name'] ?? null, 'a' => $a, 'b' => $b, 'gold_interaction' => $item['gold_interaction'], 'gold_severity' => $item['gold_severity']];
            try {
                $answer = $this->providers->text()->generate(new TextGenerationRequest(
                    prompt: "Existe interação medicamentosa conhecida entre os princípios ativos A: {$a} e B: {$b}? Responda somente JSON: {\"interaction\": \"yes\"|\"no\"|\"unsure\", \"severity\": \"major\"|\"moderate\"|\"minor\"|null}.",
                    system: 'Você é um assistente farmacêutico. Responda apenas com o JSON pedido.',
                    maxOutputTokens: self::MAX_OUTPUT_TOKENS,
                ))->content;
            } catch (AiProviderException $exception) {
                $errors++;
                $pairs[] = $pair + ['outcome' => 'provider_error', 'error' => $exception->getMessage()];

                continue;
            }
            $decoded = json_decode(preg_replace('/^```(?:json)?|```$/m', '', trim($answer)) ?? '', true);
            $says = is_array($decoded) ? ($decoded['interaction'] ?? null) : null;
            $predictedSeverity = is_array($decoded) && in_array($decoded['severity'] ?? null, ['major', 'moderate', 'minor'], true) ? $decoded['severity'] : null;
            if (! in_array($says, ['yes', 'no'], true)) {
                $abstained++;
                $pairs[] = $pair + ['outcome' => 'abstained', 'answer' => $answer];

                continue;
            }
            $positive = $says === 'yes';
            $key = match (true) {
                $item['gold_interaction'] && $positive => 'tp', $item['gold_interaction'] => 'fn', $positive => 'fp', default => 'tn',
            };
            $binary[$key]++;
            $pairs[] = $pair + ['outcome' => $key, 'predicted_severity' => $predictedSeverity];
            if ($key === 'tp' && $item['gold_severity'] !== 'unknown') {
                $severityPairs[] = ['gold' => $item['gold_severity'], 'predicted' => $predictedSeverity ?? 'unknown'];
            }
        }

        return [
            'items' => count($items), 'provider_errors' => $errors, 'abstained' => $abstained,
            'abstention_rate' => Metrics::round(count($items) > 0 ? $abstained / count($items) : null),
            'binary' => $binary + array_map(fn (?float $v): ?float => Metrics::round($v), Metrics::prf($binary['tp'], $binary['fp'], $binary['fn'])),
            'severity_confusion_matrix' => Metrics::confusionMatrix($severityPairs, ['major', 'moderate', 'minor', 'unknown']),
            'pairs' => $pairs,
        ];
    }
}
