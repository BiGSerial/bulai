<?php

namespace App\Services\Interactions;

use Illuminate\Support\Facades\DB;

/** Consulta a base estruturada de interações (DDInter) por princípios ativos já resolvidos. Fonte da existência e da gravidade. */
final class InteractionLookup
{
    /**
     * @param  list<int>  $ingredientsA
     * @param  list<int>  $ingredientsB
     */
    public function between(array $ingredientsA, array $ingredientsB): ?StructuredInteraction
    {
        if ($ingredientsA === [] || $ingredientsB === []) {
            return null;
        }

        $rows = DB::table('drug_interactions as di')
            ->join('drug_interaction_imports as i', 'i.id', '=', 'di.import_id')
            ->where(function ($query) use ($ingredientsA, $ingredientsB): void {
                $query->where(fn ($q) => $q->whereIn('di.drug_a_ingredient_id', $ingredientsA)->whereIn('di.drug_b_ingredient_id', $ingredientsB))
                    ->orWhere(fn ($q) => $q->whereIn('di.drug_a_ingredient_id', $ingredientsB)->whereIn('di.drug_b_ingredient_id', $ingredientsA));
            })
            ->orderBy('di.id')
            ->get(['di.drug_a_name', 'di.drug_b_name', 'di.severity', 'di.source', 'i.original_filename', 'i.completed_at']);

        if ($rows->isEmpty()) {
            return null;
        }

        $severities = $rows->map(fn (object $row): InteractionSeverity => InteractionSeverity::tryFrom($row->severity) ?? InteractionSeverity::Unknown);
        $top = $severities->sortByDesc(fn (InteractionSeverity $severity): int => $severity->rank())->first();
        $classified = $severities->filter(fn (InteractionSeverity $severity): bool => $severity !== InteractionSeverity::Unknown);
        $divergent = $classified->isNotEmpty() && ($classified->max(fn (InteractionSeverity $s): int => $s->rank()) - $classified->min(fn (InteractionSeverity $s): int => $s->rank())) >= 2;

        return new StructuredInteraction(
            $top,
            $rows->map(fn (object $row): array => ['drug_a' => $row->drug_a_name, 'drug_b' => $row->drug_b_name, 'severity' => $row->severity])->all(),
            $rows->map(fn (object $row): array => ['source' => strtoupper((string) $row->source) === 'DDINTER' ? 'DDInter' : (string) $row->source, 'file' => $row->original_filename, 'imported_at' => $row->completed_at])
                ->unique(fn (array $item): string => $item['source'].'|'.$item['file'])->values()->all(),
            $divergent,
        );
    }
}
