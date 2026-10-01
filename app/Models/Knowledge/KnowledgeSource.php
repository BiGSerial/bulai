<?php

namespace App\Models\Knowledge;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'collection', 'normalized_name', 'normalized_collection', 'type', 'organization', 'url', 'country', 'description', 'priority', 'active'])]
class KnowledgeSource extends Model
{
    public const TYPE_REGULATORY = 'regulatory';

    public const TYPE_CLINICAL = 'clinical';

    public const TYPE_PHARMACOLOGICAL = 'pharmacological';

    public const TYPE_SCIENTIFIC = 'scientific';

    public const TYPE_MARKETING = 'marketing';

    public const TYPE_OTHER = 'other';

    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_REGULATORY => 'Regulatória',
            self::TYPE_CLINICAL => 'Clínica',
            self::TYPE_PHARMACOLOGICAL => 'Farmacológica',
            self::TYPE_SCIENTIFIC => 'Científica',
            self::TYPE_MARKETING => 'Comercialização',
            self::TYPE_OTHER => 'Outra',
        ];
    }

    public function displayName(): string
    {
        $collection = $this->collection ?? $this->organization;

        return $collection === null || $collection === '' ? $this->name : "{$this->name} — {$collection}";
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('active', true);
    }

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

    public function batches(): HasMany
    {
        return $this->hasMany(IngestionBatch::class, 'source_id');
    }
}
