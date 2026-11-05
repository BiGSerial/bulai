<?php

namespace App\Jobs;

use App\Models\AdminReport;
use App\Services\Reports\ReportGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Gera um relatório administrativo em segundo plano. */
class GenerateReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $reportId) {}

    public function handle(ReportGenerator $generator): void
    {
        $report = AdminReport::query()->find($this->reportId);
        if ($report !== null) {
            $generator->generate($report);
        }
    }

    public function failed(?Throwable $exception): void
    {
        AdminReport::query()->whereKey($this->reportId)->whereIn('status', [AdminReport::STATUS_PENDING, AdminReport::STATUS_PROCESSING])
            ->update(['status' => AdminReport::STATUS_FAILED, 'error_code' => 'REPORT_JOB_FAILED', 'completed_at' => now()]);
    }
}
