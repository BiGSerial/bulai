<?php

namespace App\Models\Knowledge;

use Database\Factories\Knowledge\AnvisaMedicineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['source_id', 'catalog_import_id', 'record_key', 'registration_number_original', 'registration_number', 'process_number', 'product_name', 'normalized_product_name', 'regulatory_category', 'active_ingredient', 'normalized_active_ingredient', 'therapeutic_class', 'registration_holder', 'normalized_registration_holder', 'registration_status', 'process_completed_on', 'registration_expires_on', 'metadata'])]
class AnvisaMedicine extends Model
{
    /** @use HasFactory<AnvisaMedicineFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class);
    }

    public function catalogImport(): BelongsTo
    {
        return $this->belongsTo(AnvisaCatalogImport::class, 'catalog_import_id');
    }
}
