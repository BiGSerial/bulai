<?php

namespace App\Console\Commands;

use App\Services\Caregiving\CaregiverAlertService;
use App\Services\Treatment\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Envia os lembretes de dose e os avisos de fim de tratamento vencidos agora e, no modo cuidador, avisa quem cuida das
 * doses atrasadas dos pacientes que acompanha (agendado a cada 5 minutos).
 */
class SendTreatmentRemindersCommand extends Command
{
    protected $signature = 'treatment:send-reminders {--now= : Instante de referência (ISO 8601) em vez de agora; útil em testes}';

    protected $description = 'Envia lembretes de dose e avisos de fim de tratamento aos pacientes com lembretes ligados e avisos de dose atrasada a quem cuida';

    public function handle(ReminderService $reminders, CaregiverAlertService $caregiverAlerts): int
    {
        $now = $this->option('now');
        $reference = is_string($now) && $now !== '' ? CarbonImmutable::parse($now) : null;
        $totals = $reminders->run($reference);
        $alerts = $caregiverAlerts->run($reference);

        $this->info(sprintf('%d paciente(s) verificado(s): %d lembrete(s) de dose e %d aviso(s) de fim de tratamento enviados.', $totals['patients'], $totals['doses'], $totals['endings']));
        $this->info(sprintf('Modo cuidador: %d paciente(s) acompanhado(s), %d aviso(s) de dose atrasada enviados a quem cuida.', $alerts['patients'], $alerts['alerts']));

        return self::SUCCESS;
    }
}
