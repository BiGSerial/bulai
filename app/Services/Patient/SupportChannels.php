<?php

namespace App\Services\Patient;

use Illuminate\Support\Str;

/**
 * Quando a mensagem não fala de remédio, reconhece do que a pessoa precisa e indica os canais de apoio adequados, sem
 * diagnosticar: pedido vago de ajuda (pergunta o que está acontecendo, com respostas rápidas), sofrimento emocional,
 * sintoma ou mal-estar, violência, álcool e outras drogas, e "outra coisa". Determinístico (regras sobre o texto
 * normalizado), como o UrgencyDetector; a crise (risco de se machucar) continua com a triagem do InputGuard.
 *
 * Os telefones são públicos e gratuitos; a tela mostra cada situação num bloco (QuestionConversation::helpGroups).
 */
final class SupportChannels
{
    public const VAGUE = 'vago';

    public const EMOTIONAL = 'emocional';

    public const SYMPTOM = 'sintoma';

    public const VIOLENCE = 'violencia';

    public const SUBSTANCES = 'substancias';

    public const OTHER = 'outro';

    /** Telefones de ajuda: número a discar, como aparece, quem atende e quando ligar. Revisados em 18/11/2026. */
    public const LINES = [
        'cvv' => ['tel' => '188', 'number' => '188', 'name' => 'CVV', 'detail' => 'Conversar agora, de graça, 24 horas'],
        'samu' => ['tel' => '192', 'number' => '192', 'name' => 'SAMU', 'detail' => 'Emergência: perigo agora ou passando mal'],
        'intoxicacao' => ['tel' => '08007226001', 'number' => '0800 722 6001', 'name' => 'Disque-Intoxicação', 'detail' => 'Tomou remédio a mais ou por engano'],
        'policia' => ['tel' => '190', 'number' => '190', 'name' => 'Polícia', 'detail' => 'Alguém corre perigo agora'],
        'saude' => ['tel' => '136', 'number' => '136', 'name' => 'Disque Saúde', 'detail' => 'Dúvidas de saúde e onde ser atendido, de graça'],
        'mulher' => ['tel' => '180', 'number' => '180', 'name' => 'Central de Atendimento à Mulher', 'detail' => 'Violência contra a mulher, 24 horas'],
        'direitos' => ['tel' => '100', 'number' => '100', 'name' => 'Disque Direitos Humanos', 'detail' => 'Denúncias de violência, 24 horas'],
    ];

    /** Respostas rápidas do pedido vago: cada uma é enviada como a próxima mensagem. */
    public const VAGUE_OPTIONS = ['Tenho uma dúvida sobre um remédio', 'Não estou me sentindo bem', 'Estou passando por um momento difícil', 'Quero falar de outra coisa'];

    /** Na ordem de prioridade: a primeira situação reconhecida vale. */
    private const PATTERNS = [
        self::VIOLENCE => '/\b(me (bate|bateu|batem|agride|agrediu|ameaca|ameacou|machuca|machucou|estuprou|abusou|abusa)|apanh(ei|o|ando|ou)|agredid[ao]s?|violencia|abuso sexual|estupr\w*|assedi\w*|ameacad[ao]|tenho medo d[oa] meu|tenho medo d[oa] minha)\b/',
        self::SUBSTANCES => '/\b(alcool|alcoolism\w*|alcoolatra|(bebo|bebendo|beber|bebe) (demais|muito|todo dia|todos os dias)|bebedeira|drogas?|cocaina|crack|maconha|viciad[ao]s?|vicio|dependencia quimica|dependente quimic\w*|parar de beber|parar de usar)\b/',
        self::SYMPTOM => '/\b(dor|dores|doendo|doi|passando mal|passei mal|mal estar|febre|tontura|tont[ao]|enjoo|enjoad[ao]|vomit\w*|diarreia|falta de ar|nao (estou|to|me sinto|estou me sentindo) bem|me sinto mal|sentindo mal|sangra\w*|tosse|fraqueza|desmai\w*|coceira|machuquei)\b/',
        self::EMOTIONAL => '/\b(triste|tristeza|deprimid[ao]|depressao|ansios[ao]|ansiedade|angusti\w*|solidao|sozinh[ao]|desanimad[ao]|desanimo|nao aguento mais|chorando|choro muito|panico|estressad[ao]|estresse|momento dificil|sem esperanca|nervos[ao]|preocupad[ao])\b/',
        self::OTHER => '/\b(outra coisa|outro assunto)\b/',
        self::VAGUE => '/^((oi|ola|ei|bom dia|boa tarde|boa noite|por favor|olha|entao) )*(eu )?(oi|ola|bom dia|boa tarde|boa noite|socorro|ajuda|me ajud[ae]|ajud[ae] me|(preciso|quero|queria|gostaria) (de )?(uma |um pouco de )?ajuda|(pode|poderia|consegue|voce pode|voce poderia|voce consegue) me ajudar|(tenho|tinha|tive|estou com|to com|tou com|ando com|estou tendo|to tendo|tem|deu) (um |uma |uns |umas )?(problema|problemas|probleminha|duvida|duvidas|pergunta|perguntas|questao|dificuldade|dificuldades|coisa)|(uma )?(duvida|pergunta)|nao sei (o que|oq) fazer|(preciso|quero|queria|gostaria de) (falar|conversar)( com alguem)?)( (por favor|aqui|agora|serio|ai|pra voce|para voce|com uma coisa|com algo))*$/',
    ];

    /** Situação reconhecida na mensagem (sem remédio citado), ou nula. */
    public static function detect(string $text): ?string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]+/', ' ', Str::lower(Str::ascii($text)))));
        if ($normalized === '') {
            return null;
        }
        foreach (self::PATTERNS as $need => $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return $need;
            }
        }

        return null;
    }

    /** O que o BULA-IA diz no balão; os canais vêm nos blocos. */
    public static function speech(string $need): string
    {
        return match ($need) {
            self::VAGUE => 'Estou aqui para ajudar. Me conte o que está acontecendo, ou toque em uma opção.',
            self::EMOTIONAL => 'Obrigado por me contar. Eu respondo sobre remédios e não substituo um profissional, mas você não precisa passar por isso sem apoio. Se em algum momento pensar em se machucar, ligue 188 ou 192 na hora.',
            self::SYMPTOM => 'Não consigo avaliar sintomas, mas posso indicar onde buscar atendimento. Se o mal-estar começou depois de algum remédio, me diga o nome dele.',
            self::VIOLENCE => 'Ninguém deve passar por violência. Há serviços que protegem e orientam você, de graça e em sigilo.',
            self::SUBSTANCES => 'Buscar ajuda é um passo importante, e há atendimento sem julgamento.',
            default => 'Eu respondo dúvidas sobre remédios, com base nas bulas. Para outros assuntos de saúde, o Disque Saúde orienta e informa onde ser atendido.',
        };
    }

    /**
     * Blocos de cada situação (título, texto curto, tom e telefones).
     *
     * @return list<array{title: string|null, text: string|null, tone: string, lines: list<array{tel: string, number: string, name: string, detail: string}>}>
     */
    public static function groups(string $need): array
    {
        $lines = fn (string ...$keys): array => array_map(fn (string $key): array => self::LINES[$key], $keys);

        return match ($need) {
            self::VAGUE => [],
            self::EMOTIONAL => [
                ['title' => 'Para conversar agora', 'tone' => 'acolhimento', 'lines' => $lines('cvv'),
                    'text' => 'No CVV, alguém ouve você agora, de graça, em sigilo e a qualquer hora. Se preferir escrever, use o chat em cvv.org.br.'],
                ['title' => 'Para ter acompanhamento', 'tone' => 'cuidado', 'lines' => $lines('saude'),
                    'text' => 'A Unidade Básica de Saúde (o posto de saúde) e o CAPS da sua cidade têm equipes que acompanham você. O Disque Saúde informa onde ficam.'],
            ],
            self::SYMPTOM => [
                ['title' => 'Sinais de alerta: ligue já', 'tone' => 'urgencia', 'lines' => $lines('samu'),
                    'text' => 'Falta de ar, dor forte no peito, desmaio, confusão, fraqueza ou boca torta de um lado, sangramento forte ou inchaço no rosto ou na garganta: ligue para o SAMU ou vá ao pronto-socorro.'],
                ['title' => 'Sem sinais de alerta', 'tone' => 'acolhimento', 'lines' => $lines('saude'),
                    'text' => 'Procure a Unidade Básica de Saúde (o posto de saúde). O Disque Saúde tira dúvidas e informa onde ser atendido.'],
            ],
            self::VIOLENCE => [
                ['title' => 'Está em perigo agora?', 'tone' => 'urgencia', 'lines' => $lines('policia', 'samu'),
                    'text' => 'Se puder, vá para um lugar seguro e ligue para a Polícia. Se alguém estiver ferido, ligue para o SAMU.'],
                ['title' => 'Para pedir ajuda ou denunciar', 'tone' => 'acolhimento', 'lines' => $lines('mulher', 'direitos'),
                    'text' => 'O 180 atende mulheres em situação de violência. O 100 recebe denúncias de violência contra crianças, pessoas idosas, pessoas com deficiência e outros grupos. Os dois funcionam 24 horas, sem precisar se identificar.'],
            ],
            self::SUBSTANCES => [
                ['title' => 'Apoio para álcool e outras drogas', 'tone' => 'acolhimento', 'lines' => $lines('saude'),
                    'text' => 'O CAPS AD (álcool e drogas) atende de graça pelo SUS, sem precisar de encaminhamento. O Disque Saúde informa o endereço mais próximo.'],
                ['title' => 'Passou mal depois de beber ou usar alguma substância?', 'tone' => 'urgencia', 'lines' => $lines('samu', 'intoxicacao'),
                    'text' => 'Ligue para o SAMU. O Disque-Intoxicação também orienta o que fazer.'],
            ],
            default => [['title' => 'Outras dúvidas de saúde', 'text' => null, 'tone' => 'acolhimento', 'lines' => $lines('saude')]],
        };
    }
}
