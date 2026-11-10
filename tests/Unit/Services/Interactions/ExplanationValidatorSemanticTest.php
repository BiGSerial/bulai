<?php

namespace Tests\Unit\Services\Interactions;

use App\Services\Interactions\ExplanationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validação heurística de ancoragem textual: além da sobreposição de vocabulário, o validador barra as alterações de sentido
 * mais comuns que a sobreposição não percebe (análise crítica do TCC, docs/tcc/31): dose trocada, negação invertida ou
 * inventada. Continua sendo heurística: não prova que a afirmação está farmacologicamente correta.
 */
final class ExplanationValidatorSemanticTest extends TestCase
{
    private const EVIDENCE = [
        'E1' => 'Adultos e adolescentes acima de 15 anos: 20 a 40 gotas em administração única ou até o máximo de 40 gotas, 4 vezes ao dia.',
        'E2' => 'Este medicamento não deve ser utilizado por mulheres grávidas nos primeiros três meses da gravidez sem orientação médica.',
        'E3' => 'A dipirona é indicada como analgésico e antitérmico para o alívio da dor e da febre em adultos e crianças.',
        'E4' => 'Pacientes com asma brônquica podem apresentar reações alérgicas graves ao usar a dipirona.',
    ];

    /** @return array<string, array{0: string, 1: list<string>, 2: string|null}> */
    public static function claims(): array
    {
        return [
            'dose fiel' => ['Adultos e adolescentes acima de 15 anos tomam de 20 a 40 gotas, até 4 vezes ao dia.', ['E1'], null],
            'dose trocada' => ['Adultos e adolescentes acima de 15 anos tomam de 20 a 60 gotas, até 4 vezes ao dia.', ['E1'], 'dose_number_not_in_evidence'],
            'frequência trocada' => ['Adultos e adolescentes acima de 15 anos tomam de 20 a 40 gotas, até 6 vezes ao dia.', ['E1'], 'dose_number_not_in_evidence'],
            'negação mantida' => ['A dipirona não deve ser usada por mulheres grávidas nos primeiros três meses sem orientação médica.', ['E2'], null],
            'negação invertida (contraindicação)' => ['A dipirona deve ser usada por mulheres grávidas nos primeiros três meses da gravidez.', ['E2'], 'negation_inverted'],
            'negação inventada' => ['A dipirona não é indicada como analgésico nem antitérmico para dor e febre em adultos.', ['E3'], 'negation_not_in_evidence'],
            'consequência atribuída ao contrário' => ['Pacientes com asma brônquica não podem apresentar reações alérgicas graves com a dipirona.', ['E4'], 'negation_not_in_evidence'],
            'afirmação sem relação com o trecho' => ['O paracetamol causa sonolência intensa quando combinado com bebidas alcoólicas.', ['E3'], 'not_grounded_in_cited_evidence'],
        ];
    }

    /** @param list<string> $evidenceIds */
    #[DataProvider('claims')]
    public function test_meaning_changes_are_rejected_and_faithful_claims_pass(string $text, array $evidenceIds, ?string $expected): void
    {
        $result = (new ExplanationValidator)->validate((string) json_encode(['claims' => [['text' => $text, 'evidence_ids' => $evidenceIds]]]), self::EVIDENCE);

        if ($expected === null) {
            $this->assertCount(1, $result['accepted'], 'afirmação fiel ao trecho deve passar');
        } else {
            $this->assertSame([['reason' => $expected]], $result['rejected']);
        }
    }
}
