<?php

namespace App\Models\Knowledge;

use Database\Factories\Knowledge\ExternalLabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bula estrangeira de um princípio ativo (hoje: bula de prescrição da FDA, obtida pelo openFDA, a mesma publicada na DailyMed),
 * com a origem para citação: SET ID, versão, título, fabricante, data da bula e link. Usada só quando as bulas brasileiras não
 * trazem a informação.
 */
#[Fillable(['source', 'ingredient_id', 'status', 'retrieved_via', 'searched_name', 'set_id', 'spl_version', 'title', 'labeler', 'published_at', 'source_url', 'unii', 'error', 'fetched_at'])]
class ExternalLabel extends Model
{
    /** @use HasFactory<ExternalLabelFactory> */
    use HasFactory;

    public const SOURCE_FDA = 'fda';

    public const VIA_OPENFDA = 'openfda';

    /** Reserva: a API da DailyMed (NLM), usada quando o openFDA falha ou não tem bula aceitável. */
    public const VIA_DAILYMED = 'dailymed';

    public const STATUS_READY = 'ready';

    /** Nenhuma bula confiável encontrada (sem bula de prescrição de princípio ativo único). */
    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_FAILED = 'failed';

    /** Crédito exibido junto de qualquer informação desta fonte. */
    public const CREDIT = 'Bula dos EUA (FDA), via openFDA e DailyMed (U.S. National Library of Medicine)';

    /** Página pública da bula na DailyMed, pelo SET ID. */
    public const PUBLIC_URL = 'https://dailymed.nlm.nih.gov/dailymed/drugInfo.cfm?setid=';

    protected function casts(): array
    {
        return [
            'published_at' => 'date',
            'fetched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ActiveIngredient, $this> */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(ActiveIngredient::class, 'ingredient_id');
    }

    /** @return HasMany<ExternalLabelSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(ExternalLabelSection::class)->orderBy('section_code')->orderBy('position');
    }
}
