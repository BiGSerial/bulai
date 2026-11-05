<?php

namespace App\Models\Knowledge;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nome alternativo (outro idioma, sal, sinônimo) de um princípio ativo; `origin` e `review_status` preservam a procedência. */
#[Fillable(['ingredient_id', 'alias', 'normalized_alias', 'language', 'origin', 'review_status'])]
class ActiveIngredientAlias extends Model
{
    public const ORIGIN_DDINTER = 'ddinter';

    public const ORIGIN_CURATED = 'curated';

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(ActiveIngredient::class, 'ingredient_id');
    }
}
