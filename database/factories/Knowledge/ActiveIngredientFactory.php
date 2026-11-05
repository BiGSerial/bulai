<?php

namespace Database\Factories\Knowledge;

use App\Models\Knowledge\ActiveIngredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActiveIngredient>
 */
class ActiveIngredientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->lexify('????????').'ine';

        return [
            'canonical_name' => ucfirst($name),
            'normalized_name' => $name,
        ];
    }
}
