<?php

namespace App\Console\Commands;

use App\Services\Health\ServiceHealthChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sonda a IA local, o OCR e os embeddings em segundo plano (agendado a cada 5 s) e guarda a leitura no cache,
 * para que o painel e o SSE nunca esperem por um serviço ocupado.
 */
#[Signature('services:probe-health')]
#[Description('Sonda os serviços auxiliares (IA local, OCR, embeddings) e grava a leitura no cache')]
class ProbeServiceHealth extends Command
{
    public function handle(ServiceHealthChecker $checker): int
    {
        foreach ($checker->refresh() as $health) {
            $this->line(sprintf('%s: %s%s', $health->service, $health->label(), $health->hint() !== null ? ' · '.$health->hint() : ''));
        }

        return self::SUCCESS;
    }
}
