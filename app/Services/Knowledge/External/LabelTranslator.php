<?php

namespace App\Services\Knowledge\External;

use App\Data\Ai\TextGenerationRequest;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;

/**
 * Tradução fiel (inglês → português do Brasil) de trechos de bulas estrangeiras. A tradução só é aceita depois de conferida:
 * os mesmos números do original (doses, percentuais, frequências) e um tamanho compatível. Recusada, o trecho fica guardado
 * em inglês e não é usado como evidência.
 */
final class LabelTranslator
{
    public function __construct(private readonly AiProviderManager $providers) {}

    /** @return array{text: string|null, model: string|null, reason: string|null} */
    public function translate(string $original): array
    {
        try {
            $response = $this->providers->text()->generate(new TextGenerationRequest(
                prompt: "<trecho>\n".$original."\n</trecho>",
                system: $this->system(),
                maxOutputTokens: min(4000, 400 + (int) ceil(mb_strlen($original) / 2)),
                model: config('bulai.external_labels.translation_model') ?: null,
                metadata: ['purpose' => 'external_label_translation'],
                effort: 'low',
            ));
        } catch (AiProviderException $exception) {
            return ['text' => null, 'model' => null, 'reason' => 'provider_'.$exception->errorCode];
        }
        $text = trim((string) preg_replace('#^\s*</?trecho>\s*|\s*</?trecho>\s*$#u', '', trim($response->content)));

        return ['text' => $this->rejection($original, $text) === null ? $text : null, 'model' => $response->model, 'reason' => $this->rejection($original, $text)];
    }

    /** Motivo da recusa da tradução, ou null quando ela confere com o original. */
    public function rejection(string $original, string $translated): ?string
    {
        if ($translated === '') {
            return 'empty';
        }
        $ratio = mb_strlen($translated) / max(1, mb_strlen($original));
        if ($ratio < 0.6 || $ratio > 1.9) {
            return 'length_mismatch';
        }
        $expected = $this->numbers($original);
        $found = $this->numbers($translated);
        // Todo número do original precisa estar na tradução; um número a mais só vale se o original o trazia por extenso
        // ("once daily" → "1 vez ao dia"). Número inventado ou trocado recusa a tradução.
        $spelled = $this->spelledNumbers($original);
        $missing = array_diff($expected, $found);
        $extra = array_diff($found, $expected, $spelled);

        return $missing === [] && $extra === [] ? null : 'numbers_changed';
    }

    /**
     * Números do texto, sem repetição e na mesma grafia nas duas línguas: vírgula vira ponto e decimais perdem os zeros à
     * direita ("2,50" = "2.5"; "1,000" = "1.000" = "1").
     *
     * @return list<string>
     */
    private function numbers(string $text): array
    {
        preg_match_all('/\d+(?:[.,]\d+)*/u', $text, $matches);
        $numbers = array_map(function (string $number): string {
            $number = str_replace(',', '.', $number);

            return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
        }, $matches[0]);
        $numbers = array_values(array_unique($numbers));
        sort($numbers);

        return $numbers;
    }

    /** @return list<string> números escritos por extenso no original em inglês, em algarismos */
    private function spelledNumbers(string $text): array
    {
        $words = ['once' => '1', 'one' => '1', 'single' => '1', 'twice' => '2', 'two' => '2', 'three' => '3', 'thrice' => '3', 'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'ten' => '10', 'half' => '0.5', 'hundred' => '100'];
        preg_match_all('/\b('.implode('|', array_keys($words)).')\b/i', $text, $matches);

        return array_values(array_unique(array_map(fn (string $word): string => $words[strtolower($word)], $matches[1])));
    }

    private function system(): string
    {
        return <<<'TXT'
Você traduz trechos de bulas de medicamentos dos Estados Unidos (DailyMed/FDA) do inglês para o português do Brasil, para uso como referência em um sistema de saúde.
Regras:
1. Tradução fiel e completa: não resuma, não omita, não acrescente, não explique e não opine.
2. Mantenha todos os números exatamente como estão, em algarismos (doses, percentuais, frequências, referências de seção como "(7.1)"). Não escreva números por extenso.
3. Use os nomes dos princípios ativos em português do Brasil (DCB), por exemplo "warfarin" → "varfarina", "acetaminophen" → "paracetamol". Mantenha nomes comerciais como estão.
4. Mantenha a estrutura em linhas: cada linha do original vira uma linha da tradução.
5. Responda só com a tradução, sem comentários e sem marcação.
TXT;
    }
}
