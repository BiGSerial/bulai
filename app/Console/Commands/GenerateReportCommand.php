<?php

namespace App\Console\Commands;

use App\Models\AdminReport;
use App\Services\Reports\ReportGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

#[Signature('reports:generate {type : analytics|knowledge_inventory|ai_usage|user_audit|evaluation} {--format= : json|csv (padrão: primeiro formato do tipo)} {--from= : Data inicial (AAAA-MM-DD)} {--to= : Data final (AAAA-MM-DD)}')]
#[Description('Gera um relatório administrativo (mesmo serviço da tela Relatórios) e grava em storage/app/reports')]
class GenerateReportCommand extends Command
{
    public function handle(ReportGenerator $generator): int
    {
        $type = (string) $this->argument('type');
        try {
            $definition = $generator->type($type);
        } catch (InvalidArgumentException) {
            $this->components->error('Tipo desconhecido. Disponíveis: '.implode(', ', array_keys($generator->types())).'.');

            return self::INVALID;
        }

        $format = (string) ($this->option('format') ?: $definition->formats()[0]);
        config(['bulai.consultation.execution' => 'sync']);
        try {
            $report = $generator->start($type, $format, ['from' => $this->option('from') ?: null, 'to' => $this->option('to') ?: null]);
        } catch (InvalidArgumentException) {
            $this->components->error('Formato inválido para este tipo. Aceitos: '.implode(', ', $definition->formats()).'.');

            return self::INVALID;
        }

        $report->refresh();
        if ($report->status !== AdminReport::STATUS_COMPLETED) {
            $this->components->error('Falha na geração: '.($report->error_code ?? 'desconhecida').'.');

            return self::FAILURE;
        }

        $this->components->info(sprintf('Gerado: %s (%d linhas, %d bytes).', Storage::disk(ReportGenerator::DISK)->path((string) $report->path), $report->rows, $report->size));

        return self::SUCCESS;
    }
}
