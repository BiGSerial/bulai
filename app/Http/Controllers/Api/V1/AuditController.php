<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\KnowledgeDocument;
use App\Services\Audit\DataQualityAuditService;
use App\Services\Audit\EmbeddingAuditService;
use App\Services\Audit\EvaluationRunsService;
use App\Services\Audit\PdfAuditService;
use App\Services\Audit\PipelineTimingService;
use App\Services\Audit\ReadabilityService;
use App\Services\Audit\TccDossierService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Auditoria da aplicação inteira para o TCC (docs/api.md): dossiê, tempos do pipeline, embeddings, qualidade e coerência
 * dos dados gravados, leitura dos PDFs armazenados, legibilidade e registros de avaliação. Só leitura, sem identificar pessoas.
 */
class AuditController extends Controller
{
    /** Tudo em uma resposta. `from`/`to` (AAAA-MM-DD) filtram só as métricas de uso; sem `from`, vale todo o período. */
    public function tcc(Request $request, TccDossierService $dossier): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return response()->json($dossier->build(
            isset($data['from']) ? CarbonImmutable::parse($data['from'])->startOfDay() : null,
            isset($data['to']) ? CarbonImmutable::parse($data['to'])->endOfDay() : null,
        ));
    }

    public function pipeline(PipelineTimingService $timings): JsonResponse
    {
        return response()->json($timings->timings());
    }

    /** `sample` (5–200) trechos para a coerência; `live=1` reembeda uma amostra pelo provedor (chama o serviço de embeddings). */
    public function embeddings(Request $request, EmbeddingAuditService $embeddings): JsonResponse
    {
        $data = $request->validate(['sample' => ['nullable', 'integer', 'min:5', 'max:200'], 'live' => ['nullable', 'boolean']]);

        return response()->json($embeddings->audit((int) ($data['sample'] ?? 40), (bool) ($data['live'] ?? false)));
    }

    /** `verify_files=1` recalcula o SHA-256 de cada PDF armazenado (lê todos os arquivos). */
    public function quality(Request $request, DataQualityAuditService $quality): JsonResponse
    {
        $data = $request->validate(['verify_files' => ['nullable', 'boolean']]);

        return response()->json($quality->audit((bool) ($data['verify_files'] ?? false)));
    }

    public function documents(PdfAuditService $pdfs): JsonResponse
    {
        return response()->json(['generated_at' => now()->toIso8601String(), 'documents' => $pdfs->documents()]);
    }

    /** Lê o PDF armazenado: páginas, extração, cobertura do índice; `text=1` inclui o texto (`page=N` escolhe a página). */
    public function pdf(Request $request, int $documentId, PdfAuditService $pdfs): JsonResponse
    {
        $document = KnowledgeDocument::query()->findOrFail($documentId);
        $data = $request->validate(['text' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json($pdfs->inspect($document, (bool) ($data['text'] ?? false), isset($data['page']) ? (int) $data['page'] : null));
    }

    public function readability(Request $request, ReadabilityService $readability): JsonResponse
    {
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:1000']]);

        return response()->json($readability->answers((int) ($data['limit'] ?? 200)));
    }

    /** Registros de `evaluation:run`; `task=identification|interactions|retrieval|generation|baseline` filtra. */
    public function evaluations(Request $request, EvaluationRunsService $evaluations): JsonResponse
    {
        $task = $request->validate(['task' => ['nullable', 'string', 'in:identification,interactions,retrieval,generation,baseline']])['task'] ?? null;

        return response()->json(['experiments' => $task === null ? $evaluations->experiments() : [], 'runs' => $evaluations->runs($task)]);
    }
}
