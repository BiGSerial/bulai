<?php

namespace App\Console\Commands;

use App\Jobs\ImportExternalLabel;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\ExternalLabel;
use App\Services\Knowledge\External\ExternalLabelImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('knowledge:import-fda-labels {ingredients?* : IDs ou nomes de princípios ativos (português ou inglês)} {--used : Princípios ativos que o sistema usa (bulas cadastradas, perguntas, consultas e tratamentos)} {--refresh : Reimporta também os já importados} {--limit=200 : Máximo de princípios ativos nesta execução} {--no-translate : Só baixa; a tradução fica para o primeiro uso} {--sync : Roda aqui, sem a fila}')]
#[Description('Importa as bulas de prescrição da FDA (openFDA/DailyMed) — interações e reações adversas, traduzidas e com a origem — como complemento das bulas brasileiras')]
class ImportFdaLabelsCommand extends Command
{
    public function handle(): int
    {
        $ids = [];
        foreach ((array) $this->argument('ingredients') as $value) {
            $found = ctype_digit((string) $value)
                ? ActiveIngredient::query()->whereKey((int) $value)->pluck('id')->all()
                : DB::table('active_ingredients')->where('normalized_name', mb_strtolower(trim((string) $value)))
                    ->pluck('id')->merge(DB::table('active_ingredient_aliases')->where('normalized_alias', mb_strtolower(trim((string) $value)))->pluck('ingredient_id'))->all();
            if ($found === []) {
                $this->components->warn('Princípio ativo não encontrado: '.$value);
            }
            $ids = [...$ids, ...$found];
        }
        if ($this->option('used')) {
            $medicineIds = DB::table('patient_medications')->whereNotNull('medicine_id')->pluck('medicine_id')
                ->merge(DB::table('medication_questions')->whereNotNull('medicine_id')->pluck('medicine_id'))
                ->merge(DB::table('interaction_pair_results')->whereNotNull('medicine_a_id')->pluck('medicine_a_id'))
                ->merge(DB::table('interaction_pair_results')->whereNotNull('medicine_b_id')->pluck('medicine_b_id'))
                ->unique()->all();
            $ids = [...$ids,
                ...DB::table('knowledge_document_ingredients')->pluck('ingredient_id')->all(),
                ...DB::table('anvisa_medicine_ingredients')->whereIn('medicine_id', $medicineIds)->pluck('ingredient_id')->all(),
            ];
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $this->option('refresh')) {
            $ids = array_values(array_diff($ids, ExternalLabel::query()->where('source', ExternalLabel::SOURCE_FDA)->whereIn('status', [ExternalLabel::STATUS_READY, ExternalLabel::STATUS_NOT_FOUND])->pluck('ingredient_id')->all()));
        }
        $ids = array_slice($ids, 0, max(1, (int) $this->option('limit')));
        if ($ids === []) {
            $this->components->info('Nada a importar.');

            return self::SUCCESS;
        }

        $translate = ! $this->option('no-translate');
        foreach ($ids as $id) {
            if ($this->option('sync')) {
                (new ImportExternalLabel($id, $translate))->handle(app(ExternalLabelImporter::class));
                $label = ExternalLabel::query()->where('source', ExternalLabel::SOURCE_FDA)->where('ingredient_id', $id)->first();
                $this->line(sprintf('#%d %s: %s%s', $id, $label?->searched_name ?? '—', $label?->status ?? '—', $label?->status === ExternalLabel::STATUS_READY ? ' · '.$label->title.' ('.$label->sections()->count().' trechos)' : ''));
            } else {
                ImportExternalLabel::dispatch($id, $translate);
            }
        }
        if (! $this->option('sync')) {
            $this->components->info(count($ids).' princípio(s) ativo(s) enviados para a fila (Admin › Fila e serviços).');
        }

        return self::SUCCESS;
    }
}
