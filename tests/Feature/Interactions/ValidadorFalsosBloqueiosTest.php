<?php

namespace Tests\Feature\Interactions;

use App\Services\Interactions\ExplanationValidator;
use Tests\TestCase;

/** Falsos bloqueios do validador demonstrados na rodada de 16/11/2026 (docs/qa/rodada-pos-correcoes-2026-11-16.md, V1 e V2). */
final class ValidadorFalsosBloqueiosTest extends TestCase
{
    /**
     * Auditoria de 16/11/2026, V1: uma frase interpretativa sem apoio emendada à gravidade registrada derrubava a afirmação
     * inteira. Agora sai só a frase sem apoio (registrada); a apoiada fica. Nenhuma frase sem apoio passa.
     */
    public function test_an_unsupported_sentence_is_dropped_without_rejecting_the_supported_one(): void
    {
        $t1 = ['T1' => 'A base de interações (DDInter) registra interação de gravidade maior entre BESILATO DE ANLODIPINO e CARBAMAZEPINA.'];
        $segments = fn (string $text): string => (string) json_encode(['coverage' => 'full', 'segments' => [['kind' => 'fact', 'text' => $text, 'evidence_ids' => ['T1']]]]);
        $validator = new ExplanationValidator;

        $result = $validator->validateSegments($segments('Existe registro de interação de gravidade maior entre anlodipino e carbamazepina. Isso quer dizer que a combinação merece atenção especial e acompanhamento de perto.'), $t1, ['asked_text' => 'anlodipino carbamazepina']);

        $this->assertSame(['Existe registro de interação de gravidade maior entre anlodipino e carbamazepina.'], array_column($result['facts'], 'text'));
        $this->assertSame([['kind' => 'fact', 'reason' => 'sentence_not_grounded', 'text' => 'Isso quer dizer que a combinação merece atenção especial e acompanhamento de perto.']], $result['rejected']);

        $none = $validator->validateSegments($segments('O remédio cura a gripe. Ele também protege o fígado.'), $t1);
        $this->assertSame([], $none['facts'], 'sem nenhuma frase apoiada, a afirmação continua recusada');
        $this->assertSame('not_grounded_in_cited_evidence', $none['rejected'][0]['reason']);

        $claims = $validator->validate((string) json_encode(['claims' => [['text' => 'Existe registro de interação de gravidade maior entre BESILATO DE ANLODIPINO e CARBAMAZEPINA. O par é perigoso para idosos.', 'evidence_ids' => ['T1']]]]), $t1);
        $this->assertSame(['Existe registro de interação de gravidade maior entre BESILATO DE ANLODIPINO e CARBAMAZEPINA.'], array_column($claims['accepted'], 'text'), 'o mesmo vale para o caminho de pares e da bula humanizada');
        $this->assertSame([['reason' => 'sentence_not_grounded', 'text' => 'O par é perigoso para idosos.']], $claims['rejected'], 'a frase apoiada não "carrega" a frase sem apoio');
    }

    /** Auditoria de 16/11/2026, V2: o conselho negado de não mexer no tratamento sem orientação não é recomendação de conduta. */
    public function test_negated_advice_to_keep_the_treatment_is_not_a_conduct_recommendation(): void
    {
        $validate = fn (string $limit): array => (new ExplanationValidator)->validateSegments((string) json_encode(['coverage' => 'partial', 'segments' => [
            ['kind' => 'fact', 'text' => 'Este medicamento pode interagir com outros remédios.', 'evidence_ids' => ['E1']],
            ['kind' => 'limit', 'text' => $limit],
        ]]), ['E1' => 'Este medicamento pode interagir com outros remédios.']);

        $this->assertCount(1, $validate('Não mude nem suspenda nenhum dos dois remédios por conta própria. O médico ou o farmacêutico pode orientar.')['limits']);
        $this->assertCount(1, $validate('Se você já usa os dois, não pare nem troque nenhum por conta própria.')['limits']);
        $this->assertSame('forbidden_recommendation', $validate('Suspenda o remédio se sentir tontura.')['rejected'][0]['reason']);
        $this->assertSame('forbidden_recommendation', $validate('Não use este remédio na gravidez.')['rejected'][0]['reason']);
        $this->assertSame('forbidden_recommendation', $validate('Não suspenda, mas reduza a dose pela metade.')['rejected'][0]['reason'], 'sem a ressalva de orientação, continua bloqueado');
    }
}
