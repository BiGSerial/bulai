<?php

namespace App\Jobs;

use App\Models\Consultation\InteractionConsultation;
use App\Services\Interactions\InteractionConsultationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunInteractionConsultation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $consultationId) {}

    public function handle(InteractionConsultationService $service): void
    {
        $consultation = InteractionConsultation::query()->find($this->consultationId);
        if ($consultation !== null) {
            $service->run($consultation);
        }
    }
}
