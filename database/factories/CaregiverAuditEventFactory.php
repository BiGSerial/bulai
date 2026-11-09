<?php

namespace Database\Factories;

use App\Models\CaregiverAuditEvent;
use App\Models\CaregiverLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CaregiverAuditEvent>
 */
class CaregiverAuditEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'caregiver_link_id' => CaregiverLink::factory(),
            'patient_id' => fn (array $attributes): int => CaregiverLink::query()->findOrFail($attributes['caregiver_link_id'])->patient_id,
            'actor_id' => fn (array $attributes): int => CaregiverLink::query()->findOrFail($attributes['caregiver_link_id'])->patient_id,
            'event' => CaregiverAuditEvent::EVENT_APPROVED,
            'details' => [],
        ];
    }
}
