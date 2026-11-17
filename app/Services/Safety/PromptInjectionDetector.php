<?php

namespace App\Services\Safety;

use Illuminate\Support\Str;

/**
 * Detecta texto com forma de comando para a IA em conteúdo que não foi escrito como pergunta: bulas (PDF, OCR, FDA), o que a
 * leitura da receita (visão) devolve e as anotações do paciente (alergias, posologia). Esse conteúdo entra nos prompts como
 * dado; aqui, o que parece instrução é retirado antes de chegar ao modelo, além da regra de "dado não confiável" de cada
 * prompt (pedido dos autores, 17/11/2026).
 *
 * As regras são mais estritas que as da pergunta (`InputGuard`): bula fala de "esquecer a dose anterior" e "instruções de
 * uso", e isso não pode ser retirado. Só conta o que se dirige ao modelo.
 */
final class PromptInjectionDetector
{
    public const OMITTED = '[trecho omitido: continha texto com forma de comando para a IA]';

    private const PATTERNS = [
        'ignore_instructions' => '/\b(ignore|ignora|ignorem|desconsidere|desconsiderem|esqueca|esquecam|forget|disregard|override)\b.{0,30}\b((as |todas as |suas |all |the |your |any )?(instrucoes|instructions|regras|rules|prompts?|diretrizes|guidelines)( (anteriores|acima|do sistema|previous|above|prior))?|(everything|tudo) (above|acima|antes|before))\b/',
        'system_prompt' => '/\b(system prompt|prompt (do|de) sistema|mensagem (do|de) sistema|developer (mode|message)|modo desenvolvedor|instrucoes? (do|de) sistema)\b/',
        'role_play' => '/\b(voce agora e|a partir de agora voce|you are now|from now on,? you|pretend (to be|you are)|finja ser|assuma o papel|jailbreak|dan mode)\b/',
        'addressing_the_model' => '/\b(chatgpt|claude|gpt-?\d\w*|llm|language model|modelo de linguagem|(assistente|assistant) (de ia|virtual|de inteligencia artificial|ai)|inteligencia artificial)\b.{0,40}\b(deve|devera|must|should|responda|answer|diga|say|reply|ignore|nao siga|do not follow)\b/',
        'new_instructions' => '/\b(novas? (instrucoes|regras) (para|a) (voce|a ia|o modelo)|new (instructions|rules)|nova tarefa|new task)\b/',
        'format_hijack' => '/("(claims|insufficient|evidence_ids|coverage|segments|risk|found|items|medicines)"\s*:|[<‹]\/?(evidencia|trecho|bula|pergunta|conversa|system)\b|[<‹]\|[a-z_]+\|[>›]|\[\/?inst\]|<<\/?sys>>)/',
    ];

    /** @return list<string> */
    public function flags(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }
        $normalized = ' '.preg_replace('/\s+/u', ' ', Str::lower(Str::ascii($text))).' ';
        $flags = [];
        foreach (self::PATTERNS as $flag => $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                $flags[] = $flag;
            }
        }

        return $flags;
    }

    /** O texto, ou o aviso de omissão quando ele tem forma de comando. */
    public function clean(string $text, string $replacement = self::OMITTED): string
    {
        return $this->flags($text) === [] ? $text : $replacement;
    }
}
