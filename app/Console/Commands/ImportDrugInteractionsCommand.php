<?php

namespace App\Console\Commands;

use App\Services\Knowledge\DrugInteractionCsvImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('knowledge:import-drug-interactions {path* : Arquivos CSV do DDInter ou diretório com eles}')]
#[Description('Importa de forma idempotente as interações medicamentosas (DDInter)')]
class ImportDrugInteractionsCommand extends Command
{
    public function handle(DrugInteractionCsvImporter $importer): int
    {
        $files = [];
        foreach ((array) $this->argument('path') as $path) {
            $found = is_dir($path) ? glob(rtrim($path, '/').'/*.csv') : [$path];
            array_push($files, ...($found ?: []));
        }
        if ($files === []) {
            $this->components->error('Nenhum arquivo CSV encontrado.');

            return self::FAILURE;
        }

        $rows = [];
        foreach ($files as $file) {
            $import = $importer->import($file);
            $rows[] = [basename($file), $import->status, $import->rows_read, $import->rows_inserted, $import->rows_updated, $import->rows_skipped, $import->rows_rejected];
        }
        $this->table(['Arquivo', 'Situação', 'Lidas', 'Novas', 'Atualizadas', 'Duplicadas', 'Rejeitadas'], $rows);

        return self::SUCCESS;
    }
}
