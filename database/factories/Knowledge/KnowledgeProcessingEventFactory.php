<?php

namespace Database\Factories\Knowledge;

use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeProcessingEvent;
use App\Models\Knowledge\KnowledgeSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeProcessingEvent>
 */
class KnowledgeProcessingEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => fn (): int => KnowledgeDocument::query()->create([
                'source_id' => KnowledgeSource::query()->firstOrCreate(['name' => 'ANVISA', 'organization' => 'ANVISA'])->id,
                'external_key' => 'document-'.fake()->unique()->uuid(),
                'title' => 'Bula de teste',
                'document_type' => 'bula',
                'metadata' => [],
                'content_status' => KnowledgeDocument::STATUS_PENDING,
                'file_status' => 'PENDING',
                'embedding_status' => 'PENDING',
            ])->id,
            'step' => KnowledgeProcessingEvent::STEP_READ_NATIVE,
            'state' => KnowledgeProcessingEvent::STATE_DONE,
            'started_at' => now()->subSeconds(5),
            'finished_at' => now(),
            'duration_ms' => 5000,
            'detail' => ['pages' => 3],
        ];
    }

    public function running(): static
    {
        return $this->state(['state' => KnowledgeProcessingEvent::STATE_RUNNING, 'finished_at' => null, 'duration_ms' => null]);
    }
}
