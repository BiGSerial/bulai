<?php

namespace App\Models\Consultation;

use App\Services\Interactions\InteractionSeverity;
use App\Services\Interactions\PairVerdict;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'consultation_id', 'position', 'medicine_a_id', 'medicine_b_id', 'label_a', 'label_b', 'ingredients_a', 'ingredients_b', 'verdict',
    'severity', 'severity_source', 'structured', 'explanation', 'evidences', 'limitations', 'generation', 'retrieval', 'timings', 'error_code',
])]
class InteractionPairResult extends Model
{
    protected function casts(): array
    {
        return [
            'verdict' => PairVerdict::class,
            'severity' => InteractionSeverity::class,
            'ingredients_a' => 'array',
            'ingredients_b' => 'array',
            'structured' => 'array',
            'evidences' => 'array',
            'limitations' => 'array',
            'generation' => 'array',
            'retrieval' => 'array',
            'timings' => 'array',
        ];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(InteractionConsultation::class, 'consultation_id');
    }
}
