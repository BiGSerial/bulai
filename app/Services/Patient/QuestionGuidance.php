<?php

namespace App\Services\Patient;

use Illuminate\Support\Str;

/**
 * Respostas fixas, escritas pela equipe, para perguntas dentro do contexto que não citam um remédio: o que significa a base
 * não registrar interação, o que é cada gravidade, de onde vêm as informações e como ver as interações do próprio
 * tratamento. Nada é gerado pela IA. Auditoria de 17/11/2026: essas perguntas ficavam sem resposta.
 */
final class QuestionGuidance
{
    private const ABSENCE = '/\b((nao (encontrou|achou|registra|registrou|tem|ha|mostrou|apareceu)|sem) .{0,40}interac.{0,80}(segur|pode tomar|posso tomar|significa|quer dizer|tranquil|liberad)|(significa|quer dizer) .{0,50}(segur|sem risco|pode tomar))/';

    private const SEVERITY = '/\b((o que|que) (e|significa|quer dizer) .{0,30}(gravidade|interacao (maior|moderada|menor|grave|leve))|gravidade (maior|moderada|menor|grave|leve|nao classificada))\b/';

    private const SOURCES = '/\b(de onde (vem|vêm|voce tira|sao|saem)|qual (e )?a fonte|quais (sao )?as fontes|onde voce (busca|pesquisa|procura)|como voce sabe|fonte das? informac|vem as informac)/';

    public const ANSWERS = [
        'absence' => "Não. Quando a base de interações não registra interação entre dois remédios, isso quer dizer apenas que ela não tem esse registro, e não que a combinação seja segura. A base não cobre todos os remédios, e cada pessoa reage de um jeito.\n\nAntes de usar dois remédios juntos, confirme com o médico ou o farmacêutico, contando todos os remédios que você usa.",
        'severity' => "A gravidade vem da base de interações DDInter.\n\n• Maior (grave): a combinação pode causar problemas sérios; em geral, só deve ser usada com acompanhamento médico de perto.\n\n• Moderada: pode piorar a saúde ou exigir ajuste de dose e acompanhamento.\n\n• Menor (leve): costuma ter pouco efeito, mas vale avisar o médico.\n\n• Sem gravidade classificada: a interação está registrada, mas a base não diz o quanto ela é séria; isso não quer dizer que seja leve.\n\nQuem decide o que fazer no seu caso é o médico ou o farmacêutico.",
        'sources' => "As respostas usam só as bulas oficiais da ANVISA cadastradas no sistema e, para interações, a base DDInter. Quando não há bula brasileira, uso a bula dos Estados Unidos (FDA), traduzida, e aviso na resposta. Cada resposta mostra de qual bula veio.\n\nO BULA-IA não substitui o médico nem o farmacêutico.",
        'treatment_empty' => 'Para ver as interações entre os remédios que você usa, cadastre-os em “Meus remédios”, com a autorização para guardar dados de saúde. Ou escreva os nomes aqui, por exemplo: “Posso tomar losartana com ibuprofeno?”.',
    ];

    /** @return array{key: string, answer: string}|null */
    public function for(string $question, bool $asksAboutTreatment): ?array
    {
        $text = Str::lower(Str::ascii($question));
        $key = match (true) {
            $asksAboutTreatment => 'treatment_empty',
            preg_match(self::ABSENCE, $text) === 1 => 'absence',
            preg_match(self::SEVERITY, $text) === 1 => 'severity',
            preg_match(self::SOURCES, $text) === 1 => 'sources',
            default => null,
        };

        return $key === null ? null : ['key' => $key, 'answer' => self::ANSWERS[$key]];
    }
}
