<?php

namespace App\Models\Knowledge;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'type', 'organization', 'url', 'description', 'priority', 'active'])]
class KnowledgeSource extends Model
{
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class, 'source_id');
    }
}
