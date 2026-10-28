<?php

namespace Database\Factories\Knowledge;

use App\Models\Knowledge\DrugInteraction;
use App\Models\Knowledge\DrugInteractionImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DrugInteraction>
 */
class DrugInteractionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $a = fake()->unique()->numberBetween(1, 9000);
        $b = $a + fake()->numberBetween(1, 900);

        return [
            'import_id' => DrugInteractionImport::factory(),
            'source' => 'ddinter',
            'pair_key' => "ddinter:{$a}:{$b}",
            'drug_a_external_id' => "DDInter{$a}",
            'drug_a_name' => "Drug {$a}",
            'normalized_drug_a_name' => "drug {$a}",
            'drug_b_external_id' => "DDInter{$b}",
            'drug_b_name' => "Drug {$b}",
            'normalized_drug_b_name' => "drug {$b}",
            'severity' => fake()->randomElement(DrugInteraction::SEVERITIES),
        ];
    }
}
