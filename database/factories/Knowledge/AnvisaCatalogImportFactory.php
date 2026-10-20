<?php

namespace Database\Factories\Knowledge;

use App\Models\Knowledge\AnvisaCatalogImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnvisaCatalogImport>
 */
class AnvisaCatalogImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_id' => null,
            'dataset_name' => 'ANVISA - Dados Abertos de Medicamentos',
            'original_filename' => 'DADOS_ABERTOS_MEDICAMENTOS.csv',
            'sha256' => fake()->unique()->sha256(),
            'encoding' => 'ISO-8859-1',
            'delimiter' => ';',
            'status' => 'COMPLETED',
            'rows_read' => 1,
            'rows_inserted' => 1,
            'rows_updated' => 0,
            'rows_skipped' => 0,
            'rows_rejected' => 0,
            'metadata' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ];
    }
}
