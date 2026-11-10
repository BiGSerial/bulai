<?php

namespace App\Models\Knowledge;

use Database\Factories\Knowledge\ExternalLabelSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Trecho de uma bula estrangeira, por seção LOINC, com o texto original e a tradução conferida. */
#[Fillable(['external_label_id', 'section_code', 'section_name', 'position', 'text_original', 'text_translated', 'translation_status', 'translation_model', 'translated_at'])]
class ExternalLabelSection extends Model
{
    /** @use HasFactory<ExternalLabelSectionFactory> */
    use HasFactory;

    /** Seções importadas (códigos LOINC das bulas SPL; no openFDA, `drug_interactions` e `adverse_reactions`) e o nome usado nas respostas. */
    public const SECTIONS = [
        '34073-7' => 'INTERAÇÕES MEDICAMENTOSAS',
        '34084-4' => 'REAÇÕES ADVERSAS',
    ];

    public const INTERACTIONS = '34073-7';

    public const ADVERSE_REACTIONS = '34084-4';

    /** Tradução aceita: conferida (mesmos números do original). Só estes trechos são usados como evidência. */
    public const TRANSLATED = 'translated';

    /** Tradução recusada na conferência ou não feita: o trecho fica guardado, mas não é usado. */
    public const TRANSLATION_REJECTED = 'rejected';

    public const TRANSLATION_PENDING = 'pending';

    /** Deslocamento do id citável dos trechos estrangeiros (negativo, para não colidir com os chunks das bulas). */
    public const EVIDENCE_ID_OFFSET = 2_000_000;

    protected function casts(): array
    {
        return ['translated_at' => 'datetime'];
    }

    /** @return BelongsTo<ExternalLabel, $this> */
    public function label(): BelongsTo
    {
        return $this->belongsTo(ExternalLabel::class, 'external_label_id');
    }

    public function evidenceId(): int
    {
        return -(self::EVIDENCE_ID_OFFSET + $this->id);
    }
}
