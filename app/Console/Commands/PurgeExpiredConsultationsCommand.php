<?php

namespace App\Console\Commands;

use App\Services\Interactions\InteractionConsultationService;
use App\Services\Patient\MedicationQuestionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('consultations:purge-expired')]
#[Description('Apaga as consultas de interações e as perguntas registradas cujo prazo de retenção venceu')]
class PurgeExpiredConsultationsCommand extends Command
{
    public function handle(InteractionConsultationService $consultations, MedicationQuestionService $questions): int
    {
        $this->components->info($consultations->purgeExpired().' consulta(s) vencida(s) apagada(s).');
        $this->components->info($questions->purgeExpired().' pergunta(s) vencida(s) apagada(s).');

        return self::SUCCESS;
    }
}
