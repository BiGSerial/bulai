<?php

namespace App\Services\Audit;

use App\Models\Knowledge\KnowledgeDocument;
use App\Services\Evaluation\Metrics;
use App\Services\Knowledge\KnowledgeFileService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * As informações gravadas estão corretas e coerentes? Regras de integridade e de coerência sobre a base de conhecimento
 * (documentos, trechos, vínculos, interações) e sobre o que a IA gravou (perguntas, citações). Cada regra devolve quantos
 * registros a violam e exemplos de ids. Só leitura; nunca lê nome ou e-mail de usuário.
 */
final class DataQualityAuditService
{
    public function __construct(private readonly KnowledgeFileService $files) {}

    /** @return array{generated_at: string, summary: array{checks: int, passed: int, errors: int, warnings: int}, groups: array<string, list<array<string, mixed>>>, chunks: array<string, mixed>, citations: array<string, mixed>} */
    public function audit(bool $verifyFileHashes = false): array
    {
        $groups = [
            'documents' => $this->documentChecks($verifyFileHashes),
            'chunks' => $this->chunkChecks(),
            'catalog_and_interactions' => $this->catalogChecks(),
            'recorded_answers' => $this->answerChecks(),
        ];
        $all = array_merge(...array_values($groups));

        return [
            'generated_at' => now()->toIso8601String(),
            'summary' => AuditCheck::summarize($all),
            'groups' => array_map(fn (array $checks): array => array_map(fn (AuditCheck $check): array => $check->toArray(), $checks), $groups),
            'chunks' => $this->chunkStatistics(),
            'citations' => $this->citationFidelity(),
        ];
    }

    /** @return list<AuditCheck> */
    private function documentChecks(bool $verifyFileHashes): array
    {
        $ready = fn (): Builder => DB::table('knowledge_documents')->where('content_status', KnowledgeDocument::STATUS_READY);
        $total = (int) $ready()->count();
        $check = fn (string $key, string $label, string $severity, Builder $query): AuditCheck => new AuditCheck($key, $label, $severity, (int) (clone $query)->count(), $total, $query->orderBy('id')->limit(5)->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $checks = [
            $check('ready_without_chunks', 'Bula pronta sem nenhum trecho indexado', AuditCheck::ERROR, $ready()->whereNotExists(fn ($query) => $query->selectRaw('1')->from('knowledge_chunks as c')->whereColumn('c.document_id', 'knowledge_documents.id'))),
            $check('ready_without_ingredient', 'Bula pronta sem princípio ativo vinculado', AuditCheck::ERROR, $ready()->whereNotExists(fn ($query) => $query->selectRaw('1')->from('knowledge_document_ingredients as di')->whereColumn('di.document_id', 'knowledge_documents.id'))),
            $check('ready_with_pending_embedding', 'Bula pronta com embedding não concluído', AuditCheck::WARNING, $ready()->where('embedding_status', '<>', 'READY')),
            $check('ready_without_hash', 'Bula pronta sem SHA-256 do arquivo', AuditCheck::ERROR, $ready()->whereNull('sha256')),
            $check('ready_without_title_or_type', 'Bula pronta sem título ou tipo', AuditCheck::ERROR, $ready()->where(fn ($query) => $query->whereRaw("trim(coalesce(title, '')) = ''")->orWhereRaw("trim(coalesce(document_type, '')) = ''"))),
        ];

        $missingFile = [];
        $hashMismatch = [];
        foreach ($ready()->get() as $document) {
            $storage = $this->files->documentStorage(KnowledgeDocument::query()->findOrFail($document->id));
            try {
                if ($storage === null || ! $this->files->objectExists($storage['disk'], $storage['key'])) {
                    $missingFile[] = (int) $document->id;
                } elseif ($verifyFileHashes && $document->sha256 !== null && $this->files->hashObject($storage['disk'], $storage['key']) !== $document->sha256) {
                    $hashMismatch[] = (int) $document->id;
                }
            } catch (Throwable) {
                $missingFile[] = (int) $document->id;
            }
        }
        $checks[] = new AuditCheck('ready_pdf_stored', 'Bula pronta com o PDF original ausente do armazenamento', AuditCheck::ERROR, count($missingFile), $total, $missingFile);
        if ($verifyFileHashes) {
            $checks[] = new AuditCheck('pdf_hash_matches', 'SHA-256 do PDF armazenado diferente do gravado no banco', AuditCheck::ERROR, count($hashMismatch), $total, $hashMismatch);
        }

        return $checks;
    }

    /** @return list<AuditCheck> */
    private function chunkChecks(): array
    {
        $total = (int) DB::table('knowledge_chunks')->count();
        $check = fn (string $key, string $label, string $severity, string $where): AuditCheck => new AuditCheck($key, $label, $severity, (int) DB::table('knowledge_chunks')->whereRaw($where)->count(), $total, DB::table('knowledge_chunks')->whereRaw($where)->orderBy('id')->limit(5)->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $gapDocuments = DB::select('select document_id from knowledge_chunks group by document_id having min(chunk_index) <> 0 or max(chunk_index) - min(chunk_index) + 1 <> count(*)');
        $duplicates = DB::select('select document_id from knowledge_chunks group by document_id having count(*) > count(distinct md5(content))');

        return [
            $check('chunk_not_empty', 'Trecho vazio ou com menos de 30 caracteres', AuditCheck::ERROR, 'char_length(trim(content)) < 30'),
            $check('chunk_encoding', 'Trecho com caractere de substituição (texto corrompido na extração)', AuditCheck::ERROR, 'position(chr(65533) in content) > 0'),
            $check('chunk_control_characters', 'Trecho com caracteres de controle', AuditCheck::WARNING, "content ~ '[\\x01-\\x08\\x0B\\x0C\\x0E-\\x1F]'"),
            $check('chunk_page_range', 'Trecho com página inicial ausente ou final menor que a inicial', AuditCheck::ERROR, 'page_start is null or (page_end is not null and page_end < page_start)'),
            $check('chunk_token_count', 'Trecho sem contagem de tokens', AuditCheck::WARNING, 'token_count is null or token_count <= 0'),
            $check('chunk_section', 'Trecho sem seção identificada', AuditCheck::INFO, "section is null or trim(section) = ''"),
            new AuditCheck('chunk_index_contiguous', 'Documento com índices de trecho que não começam em 0 ou têm lacunas', AuditCheck::ERROR, count($gapDocuments), (int) DB::table('knowledge_chunks')->distinct()->count('document_id'), array_map(fn (object $row): int => (int) $row->document_id, $gapDocuments)),
            new AuditCheck('chunk_duplicates', 'Documento com trechos idênticos repetidos (o PDF pode trazer o mesmo texto duas vezes)', AuditCheck::INFO, count($duplicates), (int) DB::table('knowledge_chunks')->distinct()->count('document_id'), array_map(fn (object $row): int => (int) $row->document_id, $duplicates)),
        ];
    }

    /** @return list<AuditCheck> */
    private function catalogChecks(): array
    {
        $interactions = (int) DB::table('drug_interactions')->count();
        $catalog = (int) DB::table('anvisa_medicines')->count();
        $count = fn (string $table, string $where): int => (int) DB::table($table)->whereRaw($where)->count();
        $unordered = (int) (DB::selectOne('select count(*) as n from (select least(drug_a_ingredient_id, drug_b_ingredient_id) as a, greatest(drug_a_ingredient_id, drug_b_ingredient_id) as b from drug_interactions where drug_a_ingredient_id is not null and drug_b_ingredient_id is not null group by 1, 2 having count(*) > 1) as t')->n ?? 0);

        return [
            new AuditCheck('interaction_ingredients_resolved', 'Interação do DDInter sem os dois princípios ativos resolvidos', AuditCheck::WARNING, $count('drug_interactions', 'drug_a_ingredient_id is null or drug_b_ingredient_id is null'), $interactions),
            new AuditCheck('interaction_not_self', 'Interação de um princípio ativo com ele mesmo', AuditCheck::ERROR, $count('drug_interactions', 'drug_a_ingredient_id is not null and drug_a_ingredient_id = drug_b_ingredient_id'), $interactions),
            new AuditCheck('interaction_unique_pair', 'Par de princípios ativos com mais de um registro do DDInter (variantes como "(topical)" e "(liposomal)" apontam para o mesmo ativo)', AuditCheck::INFO, $unordered, $interactions),
            new AuditCheck('interaction_severity', 'Interação sem gravidade', AuditCheck::WARNING, $count('drug_interactions', "severity is null or trim(severity) = ''"), $interactions),
            new AuditCheck('catalog_medicine_without_ingredient', 'Medicamento do catálogo ANVISA sem princípio ativo', AuditCheck::INFO, (int) DB::table('anvisa_medicines')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('anvisa_medicine_ingredients as mi')->whereColumn('mi.medicine_id', 'anvisa_medicines.id'))->count(), $catalog),
        ];
    }

    /** @return list<AuditCheck> */
    private function answerChecks(): array
    {
        $questions = (int) DB::table('medication_questions')->count();
        $answered = fn (): Builder => DB::table('medication_questions')->where('status', 'answered');
        $violations = fn (Builder $query): array => [(int) (clone $query)->count(), $query->orderBy('id')->limit(5)->pluck('uuid')->all()];
        [$noEvidence, $noEvidenceIds] = $violations($answered()->whereRaw("(jsonb_typeof(evidences) <> 'array' or jsonb_array_length(evidences) = 0)"));
        [$noAnswer, $noAnswerIds] = $violations($answered()->whereRaw("(answer is null or trim(answer) = '')"));
        [$noClaims, $noClaimsIds] = $violations($answered()->whereRaw("coalesce((generation->>'claims_accepted')::int, 0) = 0"));
        [$noDuration, $noDurationIds] = $violations(DB::table('medication_questions')->whereNull('duration_ms'));
        $consultations = (int) DB::table('interaction_consultations')->count();
        $emptyCompleted = DB::table('interaction_consultations as c')->where('c.status', 'COMPLETED')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('interaction_pair_results as p')->whereColumn('p.consultation_id', 'c.id'));

        return [
            new AuditCheck('answered_has_evidence', 'Pergunta "respondida" sem nenhuma evidência gravada', AuditCheck::ERROR, $noEvidence, $questions, $noEvidenceIds),
            new AuditCheck('answered_has_text', 'Pergunta "respondida" sem texto de resposta', AuditCheck::ERROR, $noAnswer, $questions, $noAnswerIds),
            new AuditCheck('answered_has_accepted_claims', 'Pergunta "respondida" sem nenhuma afirmação aceita pelo validador', AuditCheck::ERROR, $noClaims, $questions, $noClaimsIds),
            new AuditCheck('question_has_duration', 'Pergunta sem tempo de resposta registrado', AuditCheck::WARNING, $noDuration, $questions, $noDurationIds),
            new AuditCheck('completed_consultation_has_pairs', 'Consulta concluída sem nenhum par analisado', AuditCheck::ERROR, (int) (clone $emptyCompleted)->count(), $consultations, $emptyCompleted->orderBy('c.id')->limit(5)->pluck('c.uuid')->all()),
        ];
    }

    /** @return array{count: int, per_document: array{min: int|null, avg: float|null, max: int|null}, characters: array{min: int|null, avg: float|null, max: int|null}, tokens: array{min: int|null, avg: float|null, max: int|null}, by_section: array<string, int>} */
    private function chunkStatistics(): array
    {
        $perDocument = DB::selectOne('select min(n) as min, avg(n) as avg, max(n) as max from (select count(*) as n from knowledge_chunks group by document_id) as t');
        $size = DB::selectOne('select count(*) as total, min(char_length(content)) as cmin, avg(char_length(content)) as cavg, max(char_length(content)) as cmax, min(token_count) as tmin, avg(token_count) as tavg, max(token_count) as tmax from knowledge_chunks');
        $stats = fn ($min, $avg, $max): array => ['min' => $min === null ? null : (int) $min, 'avg' => $avg === null ? null : round((float) $avg, 1), 'max' => $max === null ? null : (int) $max];

        return [
            'count' => (int) $size->total,
            'per_document' => $stats($perDocument->min, $perDocument->avg, $perDocument->max),
            'characters' => $stats($size->cmin, $size->cavg, $size->cmax),
            'tokens' => $stats($size->tmin, $size->tavg, $size->tmax),
            'by_section' => DB::table('knowledge_chunks')->selectRaw("coalesce(nullif(trim(section), ''), '(sem seção)') as section, count(*) as total")->groupBy('section')->orderByDesc('total')->limit(25)->pluck('total', 'section')->map(fn ($n): int => (int) $n)->all(),
        ];
    }

    /**
     * Fidelidade das citações gravadas: o chunk citado ainda existe e o trecho exibido está dentro do texto desse chunk
     * (nas últimas 200 perguntas respondidas). Citação que aponta para um chunk apagado ou para texto que não está nele
     * indica índice reprocessado depois da resposta, ou trecho adulterado.
     *
     * @return array<string, mixed>
     */
    private function citationFidelity(): array
    {
        $normalize = fn (string $text): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', rtrim(trim($text), '….')) ?? ''));
        $checked = 0;
        $missingChunk = 0;
        $textNotFound = 0;
        $examples = [];

        $evidences = DB::table('medication_questions')->where('status', 'answered')->orderByDesc('id')->limit(200)->get(['uuid', 'evidences']);
        $chunkIds = [];
        foreach ($evidences as $row) {
            foreach (json_decode((string) $row->evidences, true) ?: [] as $evidence) {
                $chunkIds[] = (int) ($evidence['chunk_id'] ?? 0);
            }
        }
        $contents = DB::table('knowledge_chunks')->whereIn('id', array_unique($chunkIds))->pluck('content', 'id');

        foreach ($evidences as $row) {
            foreach (json_decode((string) $row->evidences, true) ?: [] as $evidence) {
                $checked++;
                $content = $contents[(int) ($evidence['chunk_id'] ?? 0)] ?? null;
                if ($content === null) {
                    $missingChunk++;
                    $examples[] = $row->uuid;
                } elseif (($excerpt = $normalize((string) ($evidence['excerpt'] ?? ''))) !== '' && ! str_contains($normalize((string) $content), $excerpt)) {
                    $textNotFound++;
                    $examples[] = $row->uuid;
                }
            }
        }

        return [
            'evidences_checked' => $checked,
            'chunk_missing' => $missingChunk,
            'excerpt_not_in_chunk' => $textNotFound,
            'faithful' => Metrics::wilson($checked - $missingChunk - $textNotFound, $checked),
            'examples' => array_values(array_unique(array_slice($examples, 0, 5))),
        ];
    }
}
