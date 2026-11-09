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
        'alergia_grave' => '/\b(falta de ar|nao consigo respirar|dificuldade (para|pra|de) respirar|garganta (fechando|inchada|apertando)|inchou (o |a |os |as )?(rosto|labio|labios|lingua|garganta|boca)|(rosto|labio|labios|lingua|garganta|boca) (inchou|inchado|inchada|inchados|inchando|esta inchando)|rosto inchado|labios inchados|lingua inchada|manchas (vermelhas )?(pelo corpo|no corpo todo)|reacao alergica)\b/',
        'alerta' => '/\b(desmaiei|desmaio|desmaiou|dor no peito|vomitando sangue|vomitei sangue|fezes pretas|sangramento (forte|que nao para)|convulsao|convulsoes|confuso|confusao mental|muito sonolento|nao acorda)\b/',
    ];

    public function __construct(private readonly AnvisaCatalogNormalizer $normalizer) {}

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
        if ($kinds === []) {
            return null;
        }
        $overdose = in_array('overdose', $kinds, true);

        return [
            'level' => 'urgent',
            'kinds' => $kinds,
            'message' => $overdose
                ? 'Se você ou alguém tomou mais do que o indicado, não espere os sintomas: ligue agora para o Disque-Intoxicação, 0800 722 6001 (gratuito, 24 horas), ou para o SAMU, 192. Se a pessoa estiver passando mal, desmaiada ou com dificuldade para respirar, ligue 192 já.'
                : 'O que você descreveu pode ser sério. Procure atendimento médico agora ou ligue para o SAMU, 192. Se estiver com falta de ar ou o rosto, a boca ou a garganta inchando, não espere: ligue 192.',
        ];
    }
}
