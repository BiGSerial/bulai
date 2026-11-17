<?php

namespace App\Services\Safety;

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
    public const CRISIS_MESSAGE = "Sinto muito que você esteja passando por um momento tão difícil. Você não precisa enfrentar isso sem apoio. Não posso informar doses ou quantidades que causem dano, mas há pessoas prontas para ouvir você agora, de graça e em sigilo.\n\n"
        ."• CVV (Centro de Valorização da Vida): ligue 188, 24 horas, ou converse pelo chat em cvv.org.br.\n\n"
        ."• Se você já tomou remédio a mais ou está em perigo agora: ligue 192 (SAMU) ou vá ao pronto-socorro mais próximo.\n\n"
        ."• Disque-Intoxicação: 0800 722 6001.\n\n"
        ."• Para cuidado contínuo, procure o CAPS ou a Unidade Básica de Saúde.\n\n"
        .'Se puder, chame alguém de confiança para ficar com você agora.';

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
