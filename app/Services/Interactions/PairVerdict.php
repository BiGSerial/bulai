<?php

namespace App\Services\Interactions;

/** Estados possíveis da análise de um par. "Não encontrada" nunca significa "segura". */
enum PairVerdict: string
{
    case InteractionFound = 'INTERACTION_FOUND';
    case NotFoundInSources = 'NOT_FOUND_IN_SOURCES';
    case InsufficientEvidence = 'INSUFFICIENT_EVIDENCE';
    case MedicationNotIdentified = 'MEDICATION_NOT_IDENTIFIED';
    case ContradictoryEvidence = 'CONTRADICTORY_EVIDENCE';
    case TechnicalError = 'TECHNICAL_ERROR';

    public function label(): string
    {
        return match ($this) {
            self::InteractionFound => 'Interação documentada',
            self::NotFoundInSources => 'Interação não identificada nas fontes consultadas',
            self::InsufficientEvidence => 'Evidência insuficiente',
            self::MedicationNotIdentified => 'Medicamento não identificado',
            self::ContradictoryEvidence => 'Evidências divergentes',
            self::TechnicalError => 'Erro técnico',
        };
    }

    public function isConclusive(): bool
    {
        return in_array($this, [self::InteractionFound, self::NotFoundInSources], true);
    }
}
