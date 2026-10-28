<?php

namespace App\Models\Knowledge;

use Database\Factories\Knowledge\DrugInteractionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Par de fármacos com a gravidade da interação (ex.: DDInter). O par é guardado uma única vez, em ordem canônica. */
#[Fillable(['import_id', 'source', 'pair_key', 'drug_a_external_id', 'drug_a_name', 'normalized_drug_a_name', 'drug_b_external_id', 'drug_b_name', 'normalized_drug_b_name', 'severity'])]
class DrugInteraction extends Model
{
    /** @use HasFactory<DrugInteractionFactory> */
    use HasFactory;

    public const SEVERITIES = ['major', 'moderate', 'minor', 'unknown'];

    /** Interações em que o fármaco (nome já normalizado) participa, de qualquer lado do par. */
    public function scopeInvolving(Builder $query, string $normalizedName): Builder
    {
        return $query->where(fn (Builder $query) => $query->where('normalized_drug_a_name', $normalizedName)->orWhere('normalized_drug_b_name', $normalizedName));
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(DrugInteractionImport::class, 'import_id');
    }
}
