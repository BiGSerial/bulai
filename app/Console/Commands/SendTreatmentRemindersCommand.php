<?php

namespace App\Console\Commands;

use App\Services\Treatment\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Envia os lembretes de dose e os avisos de fim de tratamento vencidos agora (agendado a cada 5 minutos). */
class SendTreatmentRemindersCommand extends Command
{
    protected $signature = 'treatment:send-reminders {--now= : Instante de referência (ISO 8601) em vez de agora; útil em testes}';

    protected $description = 'Envia lembretes de dose e avisos de fim de tratamento aos pacientes com lembretes ligados';

    public function handle(ReminderService $reminders): int
    {
        $now = $this->option('now');
        $totals = $reminders->run(is_string($now) && $now !== '' ? CarbonImmutable::parse($now) : null);

        $this->info(sprintf('%d paciente(s) verificado(s): %d lembrete(s) de dose e %d aviso(s) de fim de tratamento enviados.', $totals['patients'], $totals['doses'], $totals['endings']));

        return self::SUCCESS;
    }
}
