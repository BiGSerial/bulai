<?php

namespace App\Services\Interactions;

/** Gravidade conforme a taxonomia da fonte estruturada (DDInter); `unknown` = interação sem gravidade classificada. */
enum InteractionSeverity: string
{
    case Major = 'major';
    case Moderate = 'moderate';
    case Minor = 'minor';
    case Unknown = 'unknown';

    public function rank(): int
    {
        return match ($this) {
            self::Major => 3,
            self::Moderate => 2,
            self::Minor => 1,
            self::Unknown => 0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Major => 'Maior',
            self::Moderate => 'Moderada',
            self::Minor => 'Menor',
            self::Unknown => 'Gravidade não classificada pela fonte',
        };
    }
}
