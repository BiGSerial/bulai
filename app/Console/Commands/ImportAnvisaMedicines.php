<?php

namespace App\Console\Commands;

use App\Services\Knowledge\AnvisaMedicineCsvImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('knowledge:import-anvisa-medicines {path : Caminho do DADOS_ABERTOS_MEDICAMENTOS.csv} {--source-id= : Origem regulatória existente} {--encoding=ISO-8859-1 : Encoding do arquivo}')]
#[Description('Importa de forma idempotente o catálogo regulatório de medicamentos da ANVISA')]
class ImportAnvisaMedicines extends Command
{
    public function handle(AnvisaMedicineCsvImporter $importer): int
    {
        $import = $importer->import((string) $this->argument('path'), $this->option('source-id') !== null ? (int) $this->option('source-id') : null, (string) $this->option('encoding'));
        $this->components->info("Importação #{$import->id}: {$import->status}");
        $this->table(['Lidas', 'Inseridas', 'Atualizadas', 'Ignoradas', 'Rejeitadas'], [[
            $import->rows_read, $import->rows_inserted, $import->rows_updated, $import->rows_skipped, $import->rows_rejected,
        ]]);

        return self::SUCCESS;
    }
}
