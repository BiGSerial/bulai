<?php

namespace App\Services\Patient;

use App\Services\Knowledge\AnvisaCatalogNormalizer;

/**
 * Sinais de possível urgência na pergunta (superdose, reação alérgica grave, sintomas de alerta). Determinístico e
 * executado antes de qualquer consulta a documentos: a orientação de segurança vem primeiro e não depende da bula.
 * Não diagnostica; só orienta a procurar atendimento ou o centro de intoxicação.
 */
final class UrgencyDetector
{
    private const PATTERNS = [
        'overdose' => '/\b(tomei|tomou|engoli|usei|tomamos) (muito|demais|dobrado|o dobro|a mais|dois|duas|tres|varios|varias|um monte|toda a cartela|a cartela inteira|o frasco|o vidro)\b|\b(tomei|tomou|engoli|engoliu|usei|tomamos|bebi|bebeu)\b.*\b(quantidade excessiva|em excesso|mais do que (devia|deveria|o indicado|o recomendado|a bula))\b|\b(overdose|superdosagem)\b|\bintoxic/',
        // Quantidade em algarismo ou por extenso ("tomei 10 comprimidos", "engoli 3 de uma vez"), caixa ou cartela inteira.
        'overdose_quantidade' => '/\b(tomei|tomou|engoli|engoliu|usei|tomamos|tomaram|bebi|bebeu|dei|deu)\b(?:\s+\S+){0,3}?\s+([3-9]|[1-9]\d+|tres|quatro|cinco|seis|sete|oito|nove|dez|onze|doze|quinze|vinte|trinta)\s+(comprimidos?|capsulas?|pilulas?|drageas?|cartelas?|caixas?|frascos?|vidros?|envelopes?|sachês?|saches?|doses?|de uma (so )?vez)\b|\b(tomei|tomou|engoli|engoliu|tomamos|tomaram)\b.{0,40}\b(de uma (so )?vez|a caixa (toda|inteira)|toda a caixa|a cartela (toda|inteira))\b/',
        // Ingestão acidental por criança ("meu filho engoliu um comprimido").
        'ingestao_acidental' => '/\b(crianca|filho|filha|bebe|neto|neta|menino|menina|sobrinho|sobrinha)\b.{0,40}\b(tomou|engoliu|bebeu|comeu|pegou e tomou)\b|\b(tomou|engoliu|bebeu|comeu)\b.{0,20}\bsem querer\b/',
        'alergia_grave' => '/\b(falta de ar|nao consigo respirar|dificuldade (para|pra|de) respirar|garganta (fechando|inchada|apertando)|inchou (o |a |os |as )?(rosto|labio|labios|lingua|garganta|boca)|(rosto|labio|labios|lingua|garganta|boca) (inchou|inchado|inchada|inchados|inchando|esta inchando)|rosto inchado|labios inchados|lingua inchada|manchas (vermelhas )?(pelo corpo|no corpo todo)|reacao alergica)\b/',
        'alerta' => '/\b(desmaiei|desmaio|desmaiou|dor no peito|vomitando sangue|vomitei sangue|fezes pretas|sangramento (forte|que nao para)|convulsao|convulsoes|confuso|confusao mental|muito sonolento|nao acorda)\b/',
    ];

    /** Tipos que pedem a orientação do Disque-Intoxicação. */
    private const OVERDOSE_KINDS = ['overdose', 'overdose_quantidade', 'ingestao_acidental', 'secao_superdose'];

    /** Títulos de seção da bula sobre superdose ("O que fazer se alguém usar uma quantidade maior do que a indicada"). */
    private const OVERDOSE_SECTION = '/quantidade maior do que a indicada|superdose|superdosagem|overdose/';

    public function __construct(private readonly AnvisaCatalogNormalizer $normalizer) {}

    /**
     * Defesa em profundidade: a resposta usou a seção de superdose da bula e a pergunta não foi reconhecida como urgência.
     * A orientação de socorro entra mesmo assim, porque uma resposta sobre superdose nunca sai sem ela.
     *
     * @param  list<array<string, mixed>>  $evidences
     * @return array{level: string, kinds: list<string>, message: string}|null
     */
    public function fromEvidence(array $evidences): ?array
    {
        foreach ($evidences as $evidence) {
            if (preg_match(self::OVERDOSE_SECTION, $this->normalizer->text((string) ($evidence['section'] ?? ''))) === 1) {
                return $this->urgency(['secao_superdose']);
            }
        }

        return null;
    }

    /** @return array{level: string, kinds: list<string>, message: string}|null */
    public function detect(string $question): ?array
    {
        $text = ' '.$this->normalizer->text($question).' ';
        $kinds = [];
        foreach (self::PATTERNS as $kind => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $kinds[] = $kind;
            }
        }

        return $kinds === [] ? null : $this->urgency($kinds);
    }

    /**
     * @param  list<string>  $kinds
     * @return array{level: string, kinds: list<string>, message: string}
     */
    private function urgency(array $kinds): array
    {
        $overdose = array_intersect($kinds, self::OVERDOSE_KINDS) !== [];

        return [
            'level' => 'urgent',
            'kinds' => $kinds,
            'message' => $overdose
                ? 'Se você ou alguém tomou mais do que o indicado, não espere os sintomas: ligue agora para o Disque-Intoxicação, 0800 722 6001 (gratuito, 24 horas), ou para o SAMU, 192. Se a pessoa estiver passando mal, desmaiada ou com dificuldade para respirar, ligue 192 já.'
                : 'O que você descreveu pode ser sério. Procure atendimento médico agora ou ligue para o SAMU, 192. Se estiver com falta de ar ou o rosto, a boca ou a garganta inchando, não espere: ligue 192.',
        ];
    }
}
