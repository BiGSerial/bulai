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
        'system_prompt' => '/\b(system prompt|prompt (do|de) sistema|mensagem (do|de) sistema|developer (mode|message)|modo desenvolvedor|instrucoes? (do|de) sistema|(quais|qual) (sao |e )?(as |a )?suas? (instrucoes|regras|prompt|configurac\w*)|suas instrucoes (internas|ocultas|secretas)|(repita|repetir|mostre|copie|imprima) (o |a )?(texto|mensagem|conteudo|instrucoes)( que (esta|vem|veio))? (acima|anterior\w*)|what (are|is) your (instructions|prompt|rules))\b/',
        'role_play' => '/\b(voce agora e|a partir de agora voce|you are now|from now on you|act as|aja como|atue como|finja (que|ser)|pretend (to be|you are)|assuma o papel|jailbreak|dan mode)\b/',
        'new_instructions' => '/\b(novas? (instrucoes?|regras?)|new (instructions?|rules?)|nova tarefa|new task|reveal|revele|mostre|print) (o |seu |your |the )?(prompt|instrucoes|instructions|system)\b/',
        'format_hijack' => '/("?(claims|insufficient|evidence_ids|sections)"?\s*:|[<‹]\/?evidencia|[<‹]\|[a-z_]+\|[>›]|\[\/?inst\]|<<\/?sys>>|responda (somente |apenas )?(com|em) (json|xml|codigo)|output (only )?(json|code))/',
        'shell_or_sql' => '/(\brm -rf\b|\bsudo\b|\bdrop table\b|\bdelete from\b|\bselect .{1,80} from\b|\bunion select\b|[<‹]script\b|javascript:|\beval\(|base64_decode|\bcurl\b|\bwget\b|\$\{|\{\{.*\}\})/',
    ];

    /**
     * Sinais de risco de suicídio e de dano a outra pessoa, em português coloquial (texto sem acentos, minúsculo). Auditoria de
     * 17/11/2026: a versão anterior deixava passar 14 de 16 frases de crise ("qual a dose de rivotril que mata", "quero dormir
     * e não acordar mais"). A interpretação pela IA (`QuestionInterpreter`, campo `risk`) cobre o que as regras não pegam.
     */
    private const HARM = [
        'self_harm' => '/\b(me matar|suicid\w*|tirar (a )?minha (propria )?vida|acabar com (a )?(minha )?vida|nao (quero|aguento) mais (viver|existir)|quero (morrer|sumir|desaparecer)|vontade de (morrer|sumir)|(quero|pra|para) acabar com (tudo|isso)|nao (acordar|acorde) mais|dormir (e nao acordar|pra sempre|para sempre)|nao vale (mais )?a pena viver|sem (motivo|razao) (para|pra) viver|pensando em (me matar|tomar tudo|tomar todos?|tomar (a|uma) (cartela|caixa))|kill myself|end my life|want to die)\b/',
        'lethal_dose' => '/\b(dose (letal|fatal|mortal)|quant\w+ .{0,40}(para|pra) (morrer|matar|nao acordar)|quant\w+ .{0,40}\b(e|seria) (fatal|letal|mortal)\b|(qual|que|quais) (a )?(dose|quantidade|remedio|medicamento|comprimido)s? .{0,40}\b(mata|matam|e fatal|e letal|e mortal|causa(m)? (a )?morte|leva(m)? a morte|faz(em)? morrer)\b(?! (as |os |a |o )?(bacteria|piolho|fungo|verme|germe|virus|dor|pulga|sarna|carrapato|lombriga|bicho|micro))|causa(r)? (a )?morte|(se eu|e se eu) (tomar|engolir) .{0,60}\beu (morro|morreria|vou morrer)|(cartela|caixa|vidro|frasco) (inteir[ao]|tod[ao]).{0,40}morr|overdose (proposital|de proposito)|matar (alguem|minha|meu|uma pessoa)|how (many|much) .{0,30}(to die|kill|lethal))\b/',
        'harm_others' => '/\b(envenenar|poison|dopar (alguem|uma pessoa|ela|ele|meu|minha|o |a )|drogar (alguem|uma pessoa|ela|ele)|sedar (alguem|uma pessoa|ela|ele) (sem|escondido)|apagar (alguem|uma pessoa|ela|ele)|boa noite cinderela|sem (a pessoa|ela|ele) (saber|perceber)|(na|no) (bebida|comida|cafe|suco|cha) (de alguem|dele|dela|da pessoa)|escondido (na|no) (bebida|comida|cafe|suco|cha))/',
    ];

    /** Uso fora da bula: efeito de droga, obter sem receita, triturar para aspirar ou injetar; interromper a gravidez com remédio. */
    private const MISUSE = [
        'recreational' => '/\b(ficar (chapad|doidao|doida|loucao|louco|louca|alto|alta|brisad|lesad)\w*|(dar|da|deu) (onda|barato|brisa)|efeito (recreativo|de droga|de barato)|curtir (o efeito|a onda|a brisa)|chapar|ficar na brisa)\b/',
        'no_prescription' => '/\b((como|onde) (conseguir|arrumar|obter|comprar|adquirir) .{0,40}sem receita|sem receita .{0,20}(onde|como) (conseguir|arrumar|comprar)|receita falsa|falsificar (uma )?receita)\b/',
        'other_route' => '/\b((triturar|macerar|amassar|moer) .{0,40}(cheirar|aspirar|injetar|fumar)|(cheirar|aspirar|injetar|fumar) (o |a |um |uma )?(comprimido|capsula|remedio|po)\w*)\b/',
    ];

    private const SENSITIVE = [
        'pregnancy_termination' => '/\b((como|quant\w+ .{0,30}(para|pra)) (abortar|provocar (um |o )?aborto|tirar (o |um )?(bebe|nenem|feto))|remedio (para|pra) abortar)\b/',
    ];

    /** Informação interna do sistema ou dados de outras pessoas. */
    private const CONFIDENTIAL = [
        'secrets' => '/\b((sua|seu) (chave|token|senha|api key|apikey|credencia\w*)|api ?key|(chave|token|senha|credencia\w*)s? (da |do |de )?(api|sistema|servidor|banco|admin\w*|anthropic|openai|aplicac\w*)|(banco de dados|database).{0,40}(senha|acesso|tabelas?|dump|conteudo))\b/',
        'other_people' => '/\b(dados (de|dos|das) (outr[oa]s?|demais) (usuari|pacient|pessoa)\w*|(e-?mails?|cpfs?|telefones?|enderecos?|historicos?|perguntas|receitas|dados) (dos|das|de outros|de outras|da outra|de outra|do outro) (usuari|pacient|pessoa)\w*|(liste|listar|mostre|mostrar|exiba|exibir|passe|me de|me passe|quais sao) .{0,30}(usuarios|pacientes) (cadastrad|do sistema)\w*|(historico|perguntas) de outra pessoa)/',
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
            // Dano a outra pessoa sem sinal de crise própria tem orientação diferente (192/190, não CVV).
            return new GuardVerdict($flags === ['harm_others'] ? GuardVerdict::HARM_OTHERS : GuardVerdict::HARM_RISK, $sanitized, $flags);
        }
        if (($flags = $this->matches(self::SENSITIVE, $normalized)) !== []) {
            return new GuardVerdict(GuardVerdict::SENSITIVE, $sanitized, $flags);
        }
        if (($flags = $this->matches(self::MISUSE, $normalized)) !== []) {
            return new GuardVerdict(GuardVerdict::MISUSE, $sanitized, $flags);
        }
        if (($flags = $this->matches(self::CONFIDENTIAL, $normalized)) !== []) {
            return new GuardVerdict(GuardVerdict::CONFIDENTIAL, $sanitized, $flags);
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
