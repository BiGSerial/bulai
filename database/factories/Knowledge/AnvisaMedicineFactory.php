<?php

namespace Database\Factories\Knowledge;

use App\Models\Knowledge\AnvisaCatalogImport;
use App\Models\Knowledge\AnvisaMedicine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnvisaMedicine>
 */
class AnvisaMedicineFactory extends Factory
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
            'catalog_import_id' => AnvisaCatalogImport::factory(),
            'record_key' => 'registration:'.fake()->unique()->numerify('#########'),
            'registration_number_original' => fake()->numerify('#########'),
            'registration_number' => fake()->unique()->numerify('#########'),
            'process_number' => fake()->numerify('###############'),
            'product_name' => fake()->words(2, true),
            'normalized_product_name' => fake()->words(2, true),
            'regulatory_category' => 'Novo',
            'active_ingredient' => fake()->words(2, true),
            'normalized_active_ingredient' => fake()->words(2, true),
            'therapeutic_class' => fake()->words(2, true),
            'registration_holder' => fake()->company(),
            'normalized_registration_holder' => fake()->company(),
            'registration_status' => 'Ativo',
            'process_completed_on' => '28/03/1985',
            'registration_expires_on' => '032035',
            'metadata' => [],
        ];
    }
}
