<?php

namespace App\Services\Treatment;

use Illuminate\Support\Str;

/**
 * Converte a frequência escrita na receita ("de 8 em 8 horas", "2x ao dia", "pela manhã e à noite") em horários
 * sugeridos de dose ("HH:MM"). Determinístico, sem IA: o paciente sempre pode ajustar os horários sugeridos.
 *
 * Devolve `null` quando não dá para programar (frequência vazia/ilegível, semanal, "se precisar").
 */
final class DoseScheduleParser
{
    /**
     * @return array{times: list<string>, as_needed: bool, rule: string}|null
     */
    public function parse(?string $frequency): ?array
    {
        $text = $this->normalize($frequency);
        if ($text === '') {
            return null;
        }
        if (preg_match('/\b(se necessario|se precisar|quando (tiver|sentir|houver)|sos|em caso de|caso (tenha|sinta))\b/', $text) === 1) {
            return ['times' => [], 'as_needed' => true, 'rule' => 'as_needed'];
        }
        if (preg_match('/\b(semana|semanal|semanalmente|mes|mensal|mensalmente|quinzen)/', $text) === 1) {
            return null;
        }

        if (preg_match('/\b(\d{1,2})\s*(?:\/|em|\s)\s*\1\s*(h|hs|hr|hrs|horas?)\b/', $text, $m) === 1
            || preg_match('/\b(?:a cada|cada|de)\s+(\d{1,2})\s*(h|hs|hr|hrs|horas?)\b/', $text, $m) === 1) {
            return $this->everyHours((int) $m[1]);
        }

        $periods = $this->periods($text);
        $count = $this->timesPerDay($text);
        if ($count === null && $periods !== []) {
            return ['times' => $periods, 'as_needed' => false, 'rule' => 'periods'];
        }
        if ($count === null && preg_match('/\b(diariamente|todo dia|todos os dias|ao dia|por dia|dia)\b/', $text) === 1) {
            $count = 1;
        }
        if ($count === null) {
            return null;
        }
        if ($count === 1 && $periods !== []) {
            return ['times' => [$periods[0]], 'as_needed' => false, 'rule' => 'periods'];
        }
        if ($count === count($periods)) {
            return ['times' => $periods, 'as_needed' => false, 'rule' => 'periods'];
        }

        return $this->timesFor($count, 'count');
    }

    /** Horários padrão para N doses ao dia (config) ou distribuição uniforme a partir das 08:00. */
    public function defaultTimes(int $count): array
    {
        $defaults = (array) config('bulai.treatment.default_times');
        if (isset($defaults[$count])) {
            return array_values($defaults[$count]);
        }
        $count = max(1, min(12, $count));
        $step = intdiv(24 * 60, $count);
        $times = [];
        for ($i = 0; $i < $count; $i++) {
            $minutes = (8 * 60 + $i * $step) % (24 * 60);
            $times[] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }

        return $times;
    }

    /** @return array{times: list<string>, as_needed: bool, rule: string}|null */
    private function everyHours(int $hours): ?array
    {
        if ($hours <= 0 || $hours > 24) {
            return null;
        }
        if (24 % $hours === 0) {
            return $this->timesFor(intdiv(24, $hours), 'interval');
        }
        $times = [];
        for ($minutes = 8 * 60; $minutes < 8 * 60 + 24 * 60; $minutes += $hours * 60) {
            $m = $minutes % (24 * 60);
            $times[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        }
        sort($times);

        return ['times' => $times, 'as_needed' => false, 'rule' => 'interval'];
    }

    /** @return array{times: list<string>, as_needed: bool, rule: string} */
    private function timesFor(int $count, string $rule): array
    {
        $times = $this->defaultTimes($count);
        sort($times);

        return ['times' => $times, 'as_needed' => false, 'rule' => $rule];
    }

    private function timesPerDay(string $text): ?int
    {
        if (preg_match('/\b(\d{1,2})\s*(?:x|vez|vezes)\b/', $text, $m) === 1) {
            return (int) $m[1];
        }
        $words = ['uma' => 1, 'um' => 1, 'duas' => 2, 'dois' => 2, 'tres' => 3, 'quatro' => 4, 'cinco' => 5, 'seis' => 6];
        if (preg_match('/\b(uma|um|duas|dois|tres|quatro|cinco|seis)\s+(?:vez|vezes)\b/', $text, $m) === 1) {
            return $words[$m[1]];
        }

        return null;
    }

    /** Períodos do dia citados no texto, em ordem cronológica. @return list<string> */
    private function periods(string $text): array
    {
        $map = [
            '06:00' => '/\b(ao acordar|em jejum|jejum)\b/',
            '08:00' => '/\b(manha|cafe)\b/',
            '12:00' => '/\b(almoco|meio dia)\b/',
            '16:00' => '/\b(tarde|lanche)\b/',
            '20:00' => '/\b(jantar|noite)\b/',
            '22:00' => '/\b(ao deitar|antes de dormir|ao dormir|dormir)\b/',
        ];
        $found = [];
        foreach ($map as $time => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $found[] = $time;
            }
        }

        return $found;
    }

    private function normalize(?string $text): string
    {
        $ascii = Str::lower(Str::ascii((string) $text));
        $ascii = preg_replace('/[^a-z0-9\/]+/', ' ', $ascii) ?? '';

        return trim(preg_replace('/\s+/', ' ', $ascii) ?? '');
    }
}
