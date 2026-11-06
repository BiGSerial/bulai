<?php

namespace App\Services\Admin;

use App\Models\AiUsageLog;
use App\Models\Consultation\InteractionConsultation;
use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\PatientPrescription;
use App\Models\UnmatchedMedicineReport;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Números da Visão geral da administração: base de conhecimento, uso do aplicativo, IA, pessoas e pontos de atenção.
 * Contagens pesadas (catálogo, interações, trechos) ficam em cache por 60 s; o bloco de operação é lido ao vivo.
 */
final class OverviewService
{
    public const CACHE_SECONDS = 60;

    public function __construct(private readonly AnalyticsService $analytics) {}

    /**
     * @return array{
     *   knowledge: array{documents: int, documents_with_file: int, documents_without_file: int, documents_by_type: array<string, int>, documents_by_status: array<string, int>, chunks: int, chunks_embedded: int, embedding_coverage: float|null, documents_with_ingredients: int, medicines: int, medicines_identified: int, ingredients: int, interactions: int},
     *   usage: array{consultations_total: int, consultations_week: int, questions_total: int, questions_week: int, questions_blocked_week: int, leaflets_total: int, leaflets_week: int, prescriptions_total: int, prescriptions_week: int, grounding_rate_week: float|null, distinct_users_week: int},
     *   ai: array{calls_month: int, failed_month: int, cost_month_usd: float, latency_avg_ms: float|null, text_provider: string|null, embedding_provider: string|null, vision_provider: string|null},
     *   people: array{users: int, active: int, admins: int, logged_in_week: int, never_logged_in: int},
     *   attention: list<array{label: string, count: int, route: string, tone: string}>,
     *   workflow: array{batches_in_progress: int, pending_items: int, documents_ready: int},
     *   recent: array{batches: list<array{id: int, name: string, status: string, total: int, pending: int, errors: int, at: Carbon|null}>, questions: list<array{uuid: string, question: string, status: string, at: Carbon|null}>}
     * }
     */
    public function summary(): array
    {
        return [
            'knowledge' => Cache::remember('admin-overview:knowledge', self::CACHE_SECONDS, fn (): array => $this->knowledge()),
            'usage' => Cache::remember('admin-overview:usage', self::CACHE_SECONDS, fn (): array => $this->usage()),
            'ai' => Cache::remember('admin-overview:ai', self::CACHE_SECONDS, fn (): array => $this->ai()),
            'people' => $this->people(),
            'attention' => $this->attention(),
            'workflow' => $this->workflow(),
            'recent' => $this->recent(),
        ];
    }

    /**
     * Contagens das etapas do fluxo Adicionar → Importações → Pendências → Documentos (ao vivo: são as que mudam a cada envio).
     *
     * @return array{batches_in_progress: int, pending_items: int, documents_ready: int}
     */
    private function workflow(): array
    {
        return [
            'batches_in_progress' => IngestionBatch::query()->whereIn('status', [IngestionBatch::STATUS_PENDING, IngestionBatch::STATUS_PROCESSING])->count(),
            'pending_items' => IngestionBatchItem::query()->where('status', IngestionBatchItem::STATUS_PENDING)->count(),
            'documents_ready' => KnowledgeDocument::query()->where('content_status', KnowledgeDocument::STATUS_READY)->count(),
        ];
    }

    /**
     * Atividade recente: últimas importações e últimas perguntas do aplicativo (cinco de cada).
     *
     * @return array{batches: list<array{id: int, name: string, status: string, total: int, pending: int, errors: int, at: Carbon|null}>, questions: list<array{uuid: string, question: string, status: string, at: Carbon|null}>}
     */
    private function recent(): array
    {
        $batches = IngestionBatch::query()->latest('id')->limit(5)->get()
            ->map(fn (IngestionBatch $batch): array => ['id' => $batch->id, 'name' => (string) ($batch->name ?: 'Importação #'.$batch->id), 'status' => (string) $batch->status, 'total' => (int) $batch->total_items, 'pending' => (int) $batch->pending_items, 'errors' => (int) $batch->error_items, 'at' => $batch->created_at])->all();
        $questions = DB::table('medication_questions')->select(['uuid', 'question', 'status', 'created_at'])->latest('id')->limit(5)->get()
            ->map(fn (object $row): array => ['uuid' => (string) $row->uuid, 'question' => (string) $row->question, 'status' => (string) $row->status, 'at' => $row->created_at !== null ? Carbon::parse($row->created_at) : null])->all();

        return ['batches' => $batches, 'questions' => $questions];
    }

    /** @return array<string, mixed> */
    private function knowledge(): array
    {
        $chunks = (int) DB::table('knowledge_chunks')->count();
        $embedded = (int) DB::table('knowledge_chunks')->whereNotNull('embedding_model')->count();

        return [
            'documents' => KnowledgeDocument::query()->count(),
            'documents_with_file' => KnowledgeDocument::query()->where(fn ($query) => $query->whereNotNull('storage_key')->orWhereNotNull('storage_path'))->count(),
            'documents_without_file' => KnowledgeDocument::query()->whereNull('storage_key')->whereNull('storage_path')->count(),
            'documents_by_type' => $this->groupCount('knowledge_documents', 'document_type'),
            'documents_by_status' => $this->groupCount('knowledge_documents', 'content_status'),
            'chunks' => $chunks,
            'chunks_embedded' => $embedded,
            'embedding_coverage' => $chunks > 0 ? round($embedded / $chunks, 4) : null,
            'documents_with_ingredients' => (int) DB::table('knowledge_document_ingredients')->distinct()->count('document_id'),
            'medicines' => (int) DB::table('anvisa_medicines')->count(),
            'medicines_identified' => (int) DB::table('anvisa_medicine_ingredients')->distinct()->count('medicine_id'),
            'ingredients' => (int) DB::table('active_ingredients')->count(),
            'interactions' => (int) DB::table('drug_interactions')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function usage(): array
    {
        $week = now()->subDays(7);
        $overview = $this->analytics->overview($week, now());
        $grounding = $overview['pairs']['grounding'];

        return [
            'consultations_total' => InteractionConsultation::query()->count(),
            'consultations_week' => $overview['consultations']['total'],
            'questions_total' => (int) DB::table('medication_questions')->count(),
            'questions_week' => $overview['questions']['total'],
            'questions_blocked_week' => (int) DB::table('medication_questions')->where('created_at', '>=', $week)->where('status', 'blocked')->count(),
            'leaflets_total' => (int) DB::table('medicine_leaflet_summaries')->count(),
            'leaflets_week' => $overview['leaflets']['total'],
            'prescriptions_total' => PatientPrescription::query()->where('source', PatientPrescription::SOURCE_PHOTO)->count(),
            'prescriptions_week' => PatientPrescription::query()->where('source', PatientPrescription::SOURCE_PHOTO)->where('created_at', '>=', $week)->count(),
            'grounding_rate_week' => $grounding['rate'],
            'distinct_users_week' => $overview['consultations']['distinct_users'],
        ];
    }

    /** @return array<string, mixed> */
    private function ai(): array
    {
        $month = AiUsageLog::query()->where('created_at', '>=', now()->startOfMonth());

        return [
            'calls_month' => (clone $month)->count(),
            'failed_month' => (clone $month)->where('status', AiUsageLog::STATUS_FAILED)->count(),
            'cost_month_usd' => (float) (clone $month)->sum('estimated_cost_usd'),
            'latency_avg_ms' => ($avg = (clone $month)->avg('latency_ms')) !== null ? round((float) $avg, 1) : null,
            'text_provider' => config('ai.text.default') ?: null,
            'embedding_provider' => config('ai.embedding.default') ?: null,
            'vision_provider' => config('ai.vision.default') ?: null,
        ];
    }

    /** @return array<string, int> */
    private function people(): array
    {
        return [
            'users' => User::query()->count(),
            'active' => User::query()->where('active', true)->count(),
            'admins' => User::query()->where('role', 'ADMIN')->count(),
            'logged_in_week' => User::query()->where('last_login_at', '>=', now()->subDays(7))->count(),
            'never_logged_in' => User::query()->whereNull('last_login_at')->count(),
        ];
    }

    /** @return list<array{label: string, count: int, route: string, tone: string}> */
    private function attention(): array
    {
        $items = [
            ['label' => 'arquivos aguardando sua revisão', 'count' => IngestionBatchItem::query()->where('status', IngestionBatchItem::STATUS_PENDING)->count(), 'route' => 'admin.knowledge.pending.index', 'tone' => 'warning'],
            ['label' => 'remédios de pacientes sem correspondência no catálogo', 'count' => UnmatchedMedicineReport::query()->open()->count(), 'route' => 'admin.knowledge.unmatched.index', 'tone' => 'warning'],
            ['label' => 'arquivos com erro nas importações', 'count' => IngestionBatchItem::query()->where('status', IngestionBatchItem::STATUS_ERROR)->count(), 'route' => 'admin.knowledge.batches.index', 'tone' => 'danger'],
            ['label' => 'importações ainda em processamento', 'count' => IngestionBatch::query()->whereIn('status', [IngestionBatch::STATUS_PENDING, IngestionBatch::STATUS_PROCESSING])->count(), 'route' => 'admin.knowledge.batches.index', 'tone' => 'info'],
            ['label' => 'documentos sem índice de busca', 'count' => KnowledgeDocument::query()->where('content_status', KnowledgeDocument::STATUS_READY)->where('embedding_status', '!=', KnowledgeDocument::STATUS_READY)->count(), 'route' => 'admin.knowledge.documents.index', 'tone' => 'warning'],
            ['label' => 'consultas que falharam nos últimos 7 dias', 'count' => InteractionConsultation::query()->where('status', InteractionConsultation::FAILED)->where('created_at', '>=', now()->subDays(7))->count(), 'route' => 'admin.analytics.consultations.index', 'tone' => 'danger'],
            ['label' => 'chamadas de IA com falha nos últimos 7 dias', 'count' => AiUsageLog::query()->where('status', AiUsageLog::STATUS_FAILED)->where('created_at', '>=', now()->subDays(7))->count(), 'route' => 'admin.analytics.index', 'tone' => 'danger'],
            ['label' => 'usuários ativos que nunca entraram', 'count' => User::query()->where('active', true)->whereNull('last_login_at')->count(), 'route' => 'admin.users.index', 'tone' => 'info'],
        ];

        return array_values(array_filter($items, fn (array $item): bool => $item['count'] > 0));
    }

    /** @return array<string, int> */
    private function groupCount(string $table, string $column): array
    {
        return DB::table($table)->selectRaw("coalesce({$column}, 'indefinido') as k, count(*) as c")->groupBy('k')->orderByDesc('c')->pluck('c', 'k')->map(fn ($c): int => (int) $c)->all();
    }
}
