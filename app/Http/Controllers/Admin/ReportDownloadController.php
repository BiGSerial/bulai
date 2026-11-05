<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminReport;
use App\Services\Reports\ReportGenerator;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Download de um relatório concluído (rota protegida pelo middleware `admin`). */
class ReportDownloadController extends Controller
{
    public function __invoke(AdminReport $report): StreamedResponse
    {
        abort_unless($report->status === AdminReport::STATUS_COMPLETED && $report->path !== null && Storage::disk(ReportGenerator::DISK)->exists($report->path), 404);

        return Storage::disk(ReportGenerator::DISK)->download($report->path, $report->fileName(), [
            'Content-Type' => $report->format === 'json' ? 'application/json; charset=utf-8' : 'text/csv; charset=utf-8',
        ]);
    }
}
