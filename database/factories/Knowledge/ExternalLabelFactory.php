<?php

namespace Database\Factories\Knowledge;

use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\ExternalLabel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExternalLabel> */
class ExternalLabelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source' => ExternalLabel::SOURCE_FDA,
            'ingredient_id' => ActiveIngredient::factory(),
            'status' => ExternalLabel::STATUS_READY,
            'searched_name' => 'sertraline',
            'set_id' => fake()->uuid(),
            'spl_version' => 3,
            'title' => 'ZOLOFT (SERTRALINE HYDROCHLORIDE) TABLET [PFIZER]',
            'labeler' => 'Pfizer',
            'published_at' => '2026-10-01',
            'source_url' => 'https://dailymed.nlm.nih.gov/dailymed/drugInfo.cfm?setid=x',
            'fetched_at' => now(),
        ];
    }
}
