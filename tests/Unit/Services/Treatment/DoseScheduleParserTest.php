<?php

namespace Tests\Unit\Services\Treatment;

use App\Services\Treatment\DoseScheduleParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DoseScheduleParserTest extends TestCase
{
    /** @return iterable<string, array{string|null, list<string>|null, bool}> */
    public static function frequencies(): iterable
    {
        yield 'de 8 em 8 horas' => ['de 8 em 8 horas', ['06:00', '14:00', '22:00'], false];
        yield '8/8h' => ['8/8h', ['06:00', '14:00', '22:00'], false];
        yield '12/12 horas' => ['12/12 horas', ['08:00', '20:00'], false];
        yield 'a cada 6 horas' => ['a cada 6 horas', ['00:00', '06:00', '12:00', '18:00'], false];
        yield 'a cada 24h' => ['a cada 24h', ['08:00'], false];
        yield 'intervalo que não divide o dia' => ['a cada 5 horas', ['04:00', '08:00', '13:00', '18:00', '23:00'], false];
        yield '1x ao dia' => ['1x ao dia', ['08:00'], false];
        yield 'uma vez ao dia à noite' => ['uma vez ao dia, à noite', ['20:00'], false];
        yield '2x ao dia' => ['2x ao dia', ['08:00', '20:00'], false];
        yield 'três vezes ao dia' => ['três vezes ao dia', ['06:00', '14:00', '22:00'], false];
        yield 'manhã e noite' => ['pela manhã e à noite', ['08:00', '20:00'], false];
        yield 'ao deitar' => ['1 comprimido ao deitar', ['22:00'], false];
        yield 'diariamente' => ['diariamente', ['08:00'], false];
        yield 'em jejum' => ['em jejum', ['06:00'], false];
        yield 'se necessário' => ['se necessário, até 3x ao dia', [], true];
        yield 'semanal' => ['1x por semana', null, false];
        yield 'vazio' => ['', null, false];
        yield 'nulo' => [null, null, false];
        yield 'ilegível' => ['conforme orientação', null, false];
    }

    #[DataProvider('frequencies')]
    public function test_it_turns_frequency_text_into_dose_times(?string $frequency, ?array $times, bool $asNeeded): void
    {
        $parsed = app(DoseScheduleParser::class)->parse($frequency);

        if ($times === null) {
            $this->assertNull($parsed);

            return;
        }
        $this->assertSame($times, $parsed['times']);
        $this->assertSame($asNeeded, $parsed['as_needed']);
    }

    public function test_default_times_fall_back_to_an_even_distribution_from_eight_in_the_morning(): void
    {
        $parser = app(DoseScheduleParser::class);

        $this->assertSame(['08:00', '20:00'], $parser->defaultTimes(2));
        $this->assertSame(['08:00', '12:48', '17:36', '22:24', '03:12'], $parser->defaultTimes(5));
    }
}
