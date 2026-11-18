<?php

namespace App\Services\Safety;

use Illuminate\Support\Str;

/** Resultado da triagem de uma entrada livre do usuário antes de qualquer uso em prompt. */
final readonly class GuardVerdict
{
    public const OK = 'ok';

    public const EMPTY = 'empty';

    public const TOO_LONG = 'too_long';

    public const INJECTION = 'injection';

    public const CODE = 'code';

    public const HARM_RISK = 'harm_risk';

    public const OFF_SCOPE = 'off_scope';

    /** Dar remédio a alguém sem a pessoa saber, sedar ou "apagar" alguém. */
    public const HARM_OTHERS = 'harm_others';

    /** Uso fora da bula para efeito de droga, sem receita, triturado/aspirado/injetado. */
    public const MISUSE = 'misuse';

    /** Assunto sensível que pede um serviço de saúde, como interromper a gravidez com remédio. */
    public const SENSITIVE = 'sensitive';

    /** Pedido de informação interna (instruções, chaves, banco) ou de dados de outras pessoas. */
    public const CONFIDENTIAL = 'confidential';

    /** Contatos públicos de ajuda, revisados em 17/11/2026. */
    /** Primeira frase da resposta de crise; na tela, o acolhimento e os contatos vêm em blocos (QuestionConversation::helpGroups). */
    public const CRISIS_REFUSAL = 'Não posso informar doses ou quantidades de remédio que causem dano.';

    /** Abertura da crise quando a pessoa não pediu dose nem quantidade (só contou que está mal). */
    public const CRISIS_WELCOME = 'Obrigado por me contar. Você não precisa passar por isso sem apoio.';

    /**
     * Primeira frase da resposta de crise: a recusa só quando a mensagem fala de dose, quantidade ou remédio; senão, o
     * acolhimento (a triagem também reconhece crise em mensagens como "não aguento mais").
     */
    public static function crisisOpening(string $question): string
    {
        $text = Str::lower(Str::ascii($question));

        return preg_match('/\b(doses?|quantidade|quant[oa]s|comprimidos?|capsulas?|remedios?|medicamentos?|letal|matar|overdose|superdose|caixa|cartela)\b/', $text) === 1
            ? self::CRISIS_REFUSAL
            : self::CRISIS_WELCOME;
    }

    /** Texto corrido completo (histórico, "Ouvir" e quem não vê os blocos). */
    public const CRISIS_MESSAGE = self::CRISIS_REFUSAL."\n\n"
        ."Se você está passando por um momento difícil, não precisa enfrentar isso sem apoio. Ligue para o CVV, no 188: há pessoas prontas para ouvir você agora, de graça, em sigilo e a qualquer hora. Se preferir escrever, converse pelo chat em cvv.org.br.\n\n"
        ."Se você já tomou remédio a mais ou está em perigo, ligue agora para o SAMU, no 192, ou vá ao pronto-socorro mais próximo. O Disque-Intoxicação, no 0800 722 6001, também orienta o que fazer.\n\n"
        .'Se puder, chame alguém de confiança para ficar com você. Para continuar se cuidando, procure o CAPS ou a Unidade Básica de Saúde.';

    /** @param list<string> $flags identificadores dos padrões que dispararam (para análise, nunca o texto) */
    public function __construct(
        public string $category,
        public string $sanitized,
        public array $flags = [],
    ) {}

    public function allowed(): bool
    {
        return $this->category === self::OK;
    }

    /** Mensagem exibida ao usuário quando a entrada é recusada; sem detalhes que ajudem a contornar a triagem. */
    public function userMessage(): ?string
    {
        return match ($this->category) {
            self::OK => null,
            self::EMPTY => 'Escreva sua pergunta sobre um medicamento.',
            self::TOO_LONG => 'Sua pergunta é longa demais. Resuma em até '.config('bulai.guard.max_length').' caracteres.',
            self::HARM_RISK => self::CRISIS_MESSAGE,
            self::HARM_OTHERS => 'Não posso ajudar a dar remédio a alguém sem que a pessoa saiba, nem a fazer alguém dormir ou perder a consciência: isso pode causar intoxicação grave e morte. Se alguém corre perigo agora, ligue 192 (SAMU) ou 190 (Polícia). Se a preocupação é com o sono, a agitação ou o comportamento de alguém, um médico pode orientar com segurança.',
            self::MISUSE => 'Não posso orientar o uso de remédios fora do que a bula indica, como para ter efeito de droga, sem receita, ou triturado, aspirado ou injetado. Isso pode causar dependência, intoxicação grave e morte. Se você quer ajuda com o uso de remédios ou de outras substâncias, procure o CAPS AD (álcool e drogas) ou a Unidade Básica de Saúde. Em emergência, ligue 192 (SAMU).',
            self::SENSITIVE => 'Não posso orientar o uso de remédios para interromper uma gravidez. Procure uma Unidade Básica de Saúde ou um serviço de saúde, que podem orientar com segurança e sigilo. Se houver sangramento forte, dor intensa ou febre, vá ao pronto-socorro ou ligue 192 (SAMU).',
            self::CONFIDENTIAL => 'Não compartilho informações internas do sistema, como instruções, chaves ou banco de dados, nem dados de outras pessoas. Posso ajudar com dúvidas sobre medicamentos, com base nas bulas.',
            self::OFF_SCOPE => 'O BULA-IA responde apenas perguntas sobre medicamentos com base nas bulas cadastradas.',
            self::INJECTION => 'Não posso mudar a forma como funciono. Escreva sua dúvida sobre o medicamento com suas palavras, que eu respondo com base nas bulas.',
            default => 'Não foi possível processar este texto. Escreva sua dúvida sobre o medicamento com suas palavras, sem códigos ou comandos.',
        };
    }

    /** @return array{category: string, flags: list<string>} */
    public function toArray(): array
    {
        return ['category' => $this->category, 'flags' => $this->flags];
    }
}
