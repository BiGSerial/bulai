<?php

namespace Tests\Unit\Services\Ai;

use App\Data\Ai\DocumentClassificationResponse;
use App\Services\Ai\AiProviderException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DocumentClassificationResponseTest extends TestCase
{
    public function test_valid_structured_output_is_accepted(): void
    {
        $response = DocumentClassificationResponse::fromModelOutput(json_encode($this->valid()), 'm');

        $this->assertSame('Novalgina', $response->productName);
        $this->assertSame('bula_paciente', $response->documentType);
        $this->assertSame(0.91, $response->confidence);
        $this->assertCount(2, $response->evidence);
    }

    public function test_evidence_quotes_as_long_as_the_excerpts_sent_to_the_model_are_accepted(): void
    {
        $response = DocumentClassificationResponse::fromModelOutput(json_encode([...self::valid(), 'evidence' => [str_repeat('x', 400)]]), 'qwen');

        $this->assertSame(400, mb_strlen($response->evidence[0]));
    }

    public function test_footnote_asterisks_are_removed_from_names(): void
    {
        $response = DocumentClassificationResponse::fromModelOutput(json_encode([...self::valid(), 'product_name' => 'amoxicilina*', 'active_ingredient' => 'amoxicilina tri-hidratada* + clavulanato de potássio**']), 'qwen');

        $this->assertSame('amoxicilina', $response->productName);
        $this->assertSame('amoxicilina tri-hidratada + clavulanato de potássio', $response->activeIngredient);
    }

    public function test_null_fields_and_integer_confidence_are_valid(): void
    {
        $response = DocumentClassificationResponse::fromModelOutput(json_encode([...$this->valid(), 'product_name' => null, 'document_type' => null, 'confidence' => 1]), 'm');

        $this->assertNull($response->productName);
        $this->assertSame(1.0, $response->confidence);
    }

    /** @return array<string, array{string}> */
    public static function invalidOutputs(): array
    {
        $valid = self::valid();
        $without = fn (string $key): string => json_encode(array_diff_key($valid, [$key => 1]));

        return [
            'malformed json' => ['{"product_name": "x"'],
            'prose' => ['Claro! O medicamento é Novalgina.'],
            'json list' => ['[1,2]'],
            'missing field' => [$without('evidence')],
            'extra field' => [json_encode([...$valid, 'notes' => 'x'])],
            'confidence above 1' => [json_encode([...$valid, 'confidence' => 1.5])],
            'confidence negative' => [json_encode([...$valid, 'confidence' => -0.1])],
            'confidence as string' => [json_encode([...$valid, 'confidence' => '0.9'])],
            'unknown document type' => [json_encode([...$valid, 'document_type' => 'bula_veterinaria'])],
            'empty product' => [json_encode([...$valid, 'product_name' => ' '])],
            'product not string' => [json_encode([...$valid, 'product_name' => ['a']])],
            'evidence not list of strings' => [json_encode([...$valid, 'evidence' => [1]])],
            'too much evidence' => [json_encode([...$valid, 'evidence' => array_fill(0, 6, 'x')])],
            'evidence quote over the limit' => [json_encode([...$valid, 'evidence' => [str_repeat('x', 501)]])],
        ];
    }

    #[DataProvider('invalidOutputs')]
    public function test_invalid_output_is_rejected_without_repair(string $content): void
    {
        try {
            DocumentClassificationResponse::fromModelOutput($content, 'm');
            $this->fail('Expected rejection.');
        } catch (AiProviderException $exception) {
            $this->assertSame('AI_INVALID_RESPONSE', $exception->errorCode);
        }
    }

    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return ['product_name' => 'Novalgina', 'active_ingredient' => 'dipirona monoidratada', 'document_type' => 'bula_paciente', 'confidence' => 0.91,
            'evidence' => ['produto aparece no cabeçalho', 'princípio ativo confirmado na composição']];
    }
}
