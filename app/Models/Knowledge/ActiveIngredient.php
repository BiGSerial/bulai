<?php

namespace App\Models\Knowledge;

use Database\Factories\Knowledge\ActiveIngredientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Princípio ativo canônico (nome internacional em inglês, como no DDInter) ao qual medicamentos e interações se ligam. */
#[Fillable(['canonical_name', 'normalized_name'])]
class ActiveIngredient extends Model
{
    /** @use HasFactory<ActiveIngredientFactory> */
    use HasFactory;

    public function aliases(): HasMany
    {
        return $this->hasMany(ActiveIngredientAlias::class, 'ingredient_id');
    }

    public function medicines(): BelongsToMany
    {
        return $this->belongsToMany(AnvisaMedicine::class, 'anvisa_medicine_ingredients', 'ingredient_id', 'medicine_id')
            ->withPivot(['component_text', 'method', 'confidence'])
            ->withTimestamps();
    }
}
