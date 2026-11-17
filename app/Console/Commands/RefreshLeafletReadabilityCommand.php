<?php

namespace App\Console\Commands;

use App\Models\Consultation\MedicineLeafletSummary;
use App\Models\Knowledge\AnvisaMedicine;
use App\Services\Leaflet\LeafletSummaryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leaflets:refresh-readability {--dry-run : Só lista o que seria refeito, sem chamar o modelo} {--limit=50 : Máximo de bulas refeitas nesta execução}')]
#[Description('Refaz as bulas humanizadas de versão anterior do prompt, com a medição de legibilidade (Flesch) e a reescrita até o alvo')]
class RefreshLeafletReadabilityCommand extends Command
{
    public function handle(LeafletSummaryService $service): int
    {
        $current = (string) config('bulai.leaflet.prompt_version');
        $latest = MedicineLeafletSummary::query()->orderByDesc('id')->get()->unique('ingredient_key')->values();
        $rows = [];
        $done = 0;
        foreach ($latest as $summary) {
            $before = $summary->readability();
            $outdated = $summary->prompt_version !== $current;
            if (! $outdated && $before['meets_target'] !== false) {
                continue;
            }
            $row = [$summary->subjectLabel(), $summary->prompt_version, $this->index($before['summary']['index'] ?? null)];
            if (! $outdated) {
                // Já gerada com o prompt atual e as reescritas: fica para análise no Admin, não é refeita em laço.
                $rows[] = [...$row, '—', 'abaixo do alvo após '.count($summary->generation['readability']['attempts'] ?? []).' tentativa(s)'];

                continue;
            }
            $medicine = $this->medicineFor((string) $summary->ingredient_key);
            if ($medicine === null) {
                $rows[] = [...$row, '—', 'medicamento do catálogo não encontrado'];

                continue;
            }
            if ($this->option('dry-run') || $done >= (int) $this->option('limit')) {
                $rows[] = [...$row, '—', $this->option('dry-run') ? 'seria refeita ('.$medicine->product_name.')' : 'fora do limite desta execução'];

                continue;
            }
            $fresh = $service->forMedicine($medicine);
            $done++;
            $after = $fresh?->readability();
            $rows[] = [...$row, $this->index($after['summary']['index'] ?? null), $fresh === null ? 'sem bula disponível' : (($after['meets_target'] ?? false) ? 'refeita, no alvo' : 'refeita, abaixo do alvo')];
        }

        $this->table(['Bula', 'Prompt', 'Flesch antes', 'Flesch depois', 'Situação'], $rows);
        $this->components->info(sprintf('Alvo: %s. Refeitas nesta execução: %d.', $this->index((float) config('bulai.leaflet.readability.min_flesch')), $done));

        return self::SUCCESS;
    }

    /** Um medicamento do catálogo com exatamente os princípios ativos da chave (ou, na chave por produto, o próprio produto). */
    private function medicineFor(string $key): ?AnvisaMedicine
    {
        if (str_starts_with($key, 'p:')) {
            return AnvisaMedicine::query()->where('normalized_product_name', substr($key, 2))->orderBy('id')->first();
        }
        $ids = array_map('intval', explode('-', $key));
        sort($ids);

        return AnvisaMedicine::query()->with('ingredients:id')
            ->whereHas('ingredients', fn ($query) => $query->where('active_ingredients.id', $ids[0]))
            ->orderByRaw("case when registration_status = 'Ativo' then 0 else 1 end")->orderBy('id')->limit(300)->get()
            ->first(function (AnvisaMedicine $medicine) use ($ids): bool {
                $linked = $medicine->ingredients->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

                return $linked === $ids;
            });
    }

    private function index(?float $index): string
    {
        return $index === null ? '—' : number_format($index, 1, ',', '');
    }
}
