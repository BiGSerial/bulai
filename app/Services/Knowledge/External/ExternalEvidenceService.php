<?php

namespace App\Services\Knowledge\External;

use App\Data\Knowledge\EvidenceReference;
use App\Jobs\ImportExternalLabel;
use App\Models\Knowledge\ExternalLabel;
use App\Models\Knowledge\ExternalLabelSection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Trechos da bula dos EUA (FDA, via openFDA/DailyMed) como evidência complementar: só interações e reações adversas, só
 * traduções conferidas e só quando as bulas brasileiras não cobriram a pergunta (quem chama decide isso). Cada trecho vai
 * com a origem e o crédito no título do documento, para a resposta e as fontes dizerem de onde veio.
 */
final class ExternalEvidenceService
{
    public const DOCUMENT_TYPE = 'bula_fda';

    public function __construct(private readonly ExternalLabelImporter $importer) {}

    public function enabled(): bool
    {
        return (bool) config('bulai.external_labels.enabled');
    }

    /**
     * Reações adversas: os primeiros trechos da seção (onde a bula resume as mais comuns e as graves).
     *
     * @param  list<int>  $ingredientIds
     * @return list<EvidenceReference>
     */
    public function adverseReactions(array $ingredientIds): array
    {
        return $this->references($this->sections($ingredientIds, ExternalLabelSection::ADVERSE_REACTIONS)->take($this->limit()));
    }

    /**
     * Interações de um remédio, sem outro remédio citado ("o que posso tomar com…?"): os primeiros trechos da seção.
     *
     * @param  list<int>  $ingredientIds
     * @return list<EvidenceReference>
     */
    public function interactions(array $ingredientIds): array
    {
        return $this->references($this->sections($ingredientIds, ExternalLabelSection::INTERACTIONS)->take($this->limit()));
    }

    /**
     * Interação de um par: só trechos da seção de interações de um dos lados que citam o outro pelo nome (inglês ou
     * português). Sem menção explícita, nada é usado.
     *
     * @param  list<int>  $idsA
     * @param  list<int>  $idsB
     * @return list<EvidenceReference>
     */
    public function forPair(array $idsA, array $idsB): array
    {
        $found = collect();
        foreach ([[$idsA, $idsB], [$idsB, $idsA]] as [$owners, $mentioned]) {
            $pattern = $this->namePattern($mentioned);
            if ($pattern === null) {
                continue;
            }
            $found = $found->merge($this->sections($owners, ExternalLabelSection::INTERACTIONS)->filter(fn (ExternalLabelSection $section): bool => preg_match($pattern, $section->text_original.' '.$section->text_translated) === 1));
        }

        return $this->references($found->unique('id')->take($this->limit()));
    }

    /**
     * Trechos prontos da seção, na ordem da bula. Princípio ativo sem bula estrangeira importada vai para a fila (fica pronto
     * para a próxima pergunta); trecho ainda não traduzido é traduzido agora; tradução recusada nunca é usada.
     *
     * @param  list<int>  $ingredientIds
     * @return Collection<int, ExternalLabelSection>
     */
    private function sections(array $ingredientIds, string $code): Collection
    {
        if (! $this->enabled() || $ingredientIds === []) {
            return collect();
        }
        $labels = ExternalLabel::query()->where('source', ExternalLabel::SOURCE_FDA)->whereIn('ingredient_id', $ingredientIds)->get()->keyBy('ingredient_id');
        foreach (array_diff($ingredientIds, $labels->keys()->all()) as $missing) {
            ImportExternalLabel::dispatch((int) $missing);
        }
        $ready = $labels->filter(fn (ExternalLabel $label): bool => $label->status === ExternalLabel::STATUS_READY)->pluck('id')->all();

        return ExternalLabelSection::query()->with('label')->whereIn('external_label_id', $ready)->where('section_code', $code)
            ->where('translation_status', '!=', ExternalLabelSection::TRANSLATION_REJECTED)
            ->orderBy('external_label_id')->orderBy('position')->get();
    }

    /**
     * @param  Collection<int, ExternalLabelSection>  $sections
     * @return list<EvidenceReference>
     */
    private function references(Collection $sections): array
    {
        $references = [];
        foreach ($sections as $section) {
            if ($section->translation_status === ExternalLabelSection::TRANSLATION_PENDING) {
                $section = $this->importer->translate($section);
            }
            if ($section->translation_status !== ExternalLabelSection::TRANSLATED || $section->text_translated === null) {
                continue;
            }
            $label = $section->label;
            $references[] = new EvidenceReference(
                chunkId: $section->evidenceId(),
                documentId: -$label->id,
                documentTitle: $label->title.' — bula dos EUA (FDA), tradução automática',
                documentType: self::DOCUMENT_TYPE,
                sourceName: ExternalLabel::CREDIT,
                section: $section->section_name.' (bula dos EUA)',
                pageStart: null,
                pageEnd: null,
                excerpt: $section->text_translated,
                score: 1.0,
                method: 'external_'.($label->retrieved_via ?? 'fda'),
                documentVersion: trim(($label->set_id ?? '').' v'.($label->spl_version ?? '?').($label->published_at !== null ? ' ('.$label->published_at->format('d/m/Y').')' : '')),
            );
        }

        return $references;
    }

    /** @param list<int> $ingredientIds */
    private function namePattern(array $ingredientIds): ?string
    {
        $names = DB::table('active_ingredient_aliases')->whereIn('ingredient_id', $ingredientIds)->pluck('alias')
            ->merge(DB::table('active_ingredients')->whereIn('id', $ingredientIds)->pluck('canonical_name'))
            ->map(fn ($name): string => trim((string) preg_replace('/\s*\(.*\)\s*$/', '', (string) $name)))
            ->filter(fn (string $name): bool => mb_strlen($name) >= 4)->unique()->values();

        return $names->isEmpty() ? null : '/\b('.$names->map(fn (string $name): string => preg_quote($name, '/'))->implode('|').')\b/iu';
    }

    private function limit(): int
    {
        return max(1, (int) config('bulai.external_labels.evidence_limit'));
    }
}
