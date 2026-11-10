<?php

namespace Database\Factories\Knowledge;

use App\Models\Knowledge\ExternalLabel;
use App\Models\Knowledge\ExternalLabelSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExternalLabelSection> */
class ExternalLabelSectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'external_label_id' => ExternalLabel::factory(),
            'section_code' => ExternalLabelSection::ADVERSE_REACTIONS,
            'section_name' => ExternalLabelSection::SECTIONS[ExternalLabelSection::ADVERSE_REACTIONS],
            'position' => 0,
            'text_original' => 'The most common adverse reactions (5% or more) were nausea and diarrhea.',
            'text_translated' => 'As reações adversas mais comuns (5% ou mais) foram náusea e diarreia.',
            'translation_status' => ExternalLabelSection::TRANSLATED,
            'translation_model' => 'scripted',
            'translated_at' => now(),
        ];
    }
}
