<?php

namespace Database\Factories\Knowledge;

use App\Models\Knowledge\DrugInteractionImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DrugInteractionImport>
 */
class DrugInteractionImportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dataset_name' => 'DDInter - Interações medicamentosas',
            'original_filename' => 'ddinter_downloads_code_A.csv',
            'sha256' => fake()->unique()->sha256(),
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
