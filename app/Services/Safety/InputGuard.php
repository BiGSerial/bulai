<?php

namespace App\Services\Safety;

use Illuminate\Support\Str;

/**
 * Triagem determinística de toda entrada livre do usuário ANTES de ela chegar a um prompt.
 *
 * Camadas de defesa (esta é a primeira): (1) este filtro recusa tentativas de injeção de instruções, código/markup,
 * pedidos de dano e assuntos fora da proposta; (2) o prompt de sistema trata o texto como dado não confiável e exige
 * saída JSON; (3) o `ExplanationValidator` só aceita afirmações ancoradas nas evidências e sem conduta clínica.
 * Nenhuma dessas camadas confia na outra.
 */
final class InputGuard
{
    /** Padrões avaliados sobre o texto normalizado (minúsculas, sem acentos, espaços simples). */
    private const INJECTION = [
        'ignore_instructions' => '/\b(ignore|ignora|desconsidere|esqueca|forget|disregard|override)\b.{0,40}\b(instru\w*|regras?|rules?|prompt|orientac\w*|diretriz\w*|anterior\w*|previous|above|tudo|everything)\b/',
        'system_prompt' => '/\b(system prompt|prompt (do|de) sistema|mensagem (do|de) sistema|developer (mode|message)|modo desenvolvedor|instrucoes? (do|de) sistema)\b/',
        'role_play' => '/\b(voce agora e|a partir de agora voce|you are now|from now on you|act as|aja como|atue como|finja (que|ser)|pretend (to be|you are)|assuma o papel|jailbreak|dan mode)\b/',
        'new_instructions' => '/\b(novas? (instrucoes?|regras?)|new (instructions?|rules?)|nova tarefa|new task|reveal|revele|mostre|print) (o |seu |your |the )?(prompt|instrucoes|instructions|system)\b/',
        'format_hijack' => '/("?(claims|insufficient|evidence_ids|sections)"?\s*:|[<‹]\/?evidencia|[<‹]\|[a-z_]+\|[>›]|\[\/?inst\]|<<\/?sys>>|responda (somente |apenas )?(com|em) (json|xml|codigo)|output (only )?(json|code))/',
        'shell_or_sql' => '/(\brm -rf\b|\bsudo\b|\bdrop table\b|\bdelete from\b|\bselect .{1,80} from\b|\bunion select\b|[<‹]script\b|javascript:|\beval\(|base64_decode|\bcurl\b|\bwget\b|\$\{|\{\{.*\}\})/',
    ];

    private const HARM = [
        'self_harm' => '/\b(me matar|suicid\w*|tirar (a )?minha vida|acabar com (a )?(minha )?vida|nao quero mais viver|kill myself|end my life)\b/',
        'lethal_dose' => '/\b(dose letal|quant\w+ .{0,30}(para|pra) (morrer|matar)|overdose (proposital|de proposito)|matar (alguem|minha|meu|uma pessoa))\b/',
        'harm_others' => '/\b(envenenar|poison|drogar (alguem|ela|ele)|sem (ela|ele) (saber|perceber))\b/',
    ];

    private const OFF_SCOPE = [
        'code_request' => '/\b(escreva|write|gere|generate|crie|create) (um |uma |a |o )?(codigo|code|script|programa|program|poema|poem|piada|joke|redacao|essay|email|e-mail)\b/',
        'unrelated_topics' => '/\b(futebol|bitcoin|criptomoeda|receita de (bolo|pao|torta|massa)|previsao do tempo|horoscopo|traduza|translate|resumo do (filme|livro))\b/',
    ];

    public function screen(string $input): GuardVerdict
    {
        $sanitized = $this->sanitize($input);
        $max = (int) config('bulai.guard.max_length');
        if (mb_strlen($sanitized) < (int) config('bulai.guard.min_length')) {
            return new GuardVerdict(GuardVerdict::EMPTY, $sanitized);
        }
        if (mb_strlen($sanitized) > $max) {
            return new GuardVerdict(GuardVerdict::TOO_LONG, mb_substr($sanitized, 0, $max));
        }

        $normalized = $this->normalize($sanitized);
        if (($flags = $this->matches(self::HARM, $normalized)) !== []) {
            return new GuardVerdict(GuardVerdict::HARM_RISK, $sanitized, $flags);
        }
        if (($flags = $this->matches(self::INJECTION, $normalized)) !== []) {
            return new GuardVerdict(GuardVerdict::INJECTION, $sanitized, $flags);
        }
        if (($flags = $this->codeFlags($sanitized)) !== []) {
            return new GuardVerdict(GuardVerdict::CODE, $sanitized, $flags);
        }
        if (($flags = $this->matches(self::OFF_SCOPE, $normalized)) !== []) {
            return new GuardVerdict(GuardVerdict::OFF_SCOPE, $sanitized, $flags);
        }

        return new GuardVerdict(GuardVerdict::OK, $sanitized);
    }

    /** Remove caracteres de controle e invisíveis (usados para esconder instruções), neutraliza tags e normaliza espaços. */
    public function sanitize(string $input): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', $input) ?? $input;
        $text = str_replace(['<', '>'], ['‹', '›'], $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function normalize(string $text): string
    {
        // Mantém os marcadores ‹› (tags neutralizadas) visíveis aos padrões.
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii(str_replace(['‹', '›'], ['<', '>'], $text)))));
    }

    /**
     * @param  array<string, string>  $patterns
     * @return list<string>
     */
    private function matches(array $patterns, string $normalized): array
    {
        $flags = [];
        foreach ($patterns as $flag => $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                $flags[] = $flag;
            }
        }

        return $flags;
    }

    /**
     * Texto que parece código ou markup (muitos símbolos, chaves/ponto-e-vírgula, várias linhas de instrução) em vez de
     * uma pergunta em linguagem natural.
     *
     * @return list<string>
     */
    private function codeFlags(string $text): array
    {
        $flags = [];
        $length = max(1, mb_strlen($text));
        $symbols = preg_match_all('/[{}\[\]();=<>‹›|\\\\$#`~^]/u', $text);
        if ($symbols / $length > 0.12 && $symbols >= 6) {
            $flags[] = 'symbol_density';
        }
        if (preg_match('/[{\[].{0,80}["\']\w+["\']\s*:/', $text) === 1) {
            $flags[] = 'json_like';
        }
        if (preg_match('/^\s*(#|\/\/|--|\$|>)\s*\S/m', $text) === 1 && preg_match_all('/;|\{|\}/', $text) >= 3) {
            $flags[] = 'script_like';
        }

        return $flags;
    }
}
