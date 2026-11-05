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
            self::HARM_RISK => 'Se você está pensando em se machucar, procure ajuda agora: ligue 188 (CVV, 24 h) ou vá ao serviço de emergência mais próximo. O BULA-IA não responde a perguntas sobre doses para causar dano.',
            self::OFF_SCOPE => 'O BULA-IA responde apenas perguntas sobre medicamentos com base nas bulas cadastradas.',
            default => 'Não foi possível processar este texto. Escreva sua dúvida sobre o medicamento com suas palavras, sem códigos ou comandos.',
        };
    }

    /** @return array{category: string, flags: list<string>} */
    public function toArray(): array
    {
        return ['category' => $this->category, 'flags' => $this->flags];
    }
}
