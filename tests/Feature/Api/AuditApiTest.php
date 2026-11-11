<?php

namespace Tests\Feature\Api;

use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;
use App\Models\Consultation\MedicationQuestion;
use App\Models\Knowledge\IngestionToken;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeProcessingEvent;
use App\Models\Knowledge\KnowledgeSource;
use App\Services\Audit\ReadabilityService;
use App\Services\Evaluation\Metrics;
use App\Services\Knowledge\PdfTextExtractor;
use Database\Factories\Consultation\MedicationQuestionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** API de auditoria para o TCC (`/api/v1/audit/*`, docs/api.md): embeddings, qualidade dos dados, PDFs, tempos e legibilidade. */
final class AuditApiTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE_ONE = 'O omeprazol é indicado para o tratamento de úlceras gástricas e duodenais, esofagite de refluxo e síndrome de Zollinger-Ellison em adultos. A dose usual é de vinte miligramas ao dia, tomada pela manhã.';

    private const PAGE_TWO = 'As reações adversas mais comuns incluem dor de cabeça, diarreia, náusea, dor abdominal e flatulência. Informe ao médico se os sintomas persistirem ou se agravarem durante o tratamento.';

    private function token(): string
    {
        return IngestionToken::issue('agente', IngestionToken::SCOPE_API_READ, now()->addDay())[1];
    }

    /** @return array{0: KnowledgeDocument, 1: list<int>} */
    private function documentWithChunks(): array
    {
        Storage::fake('local');
        $source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'type' => 'document', 'active' => true]);
        $document = KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => 'omeprazol', 'title' => 'Omeprazol — Bula', 'document_type' => 'bula_paciente', 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'READY', 'content_hash' => 'h', 'sha256' => hash('sha256', '%PDF-omeprazol'), 'file_size' => 14]);
        $key = "knowledge/originals/{$document->id}/omeprazol.pdf";
        Storage::disk('local')->put($key, '%PDF-omeprazol');
        $document->forceFill(['storage_path' => $key, 'storage_key' => $key, 'storage_disk' => 'local'])->save();

        $vector = '['.implode(',', array_fill(0, 8, 0.35355339)).']';
        $ids = [];
        foreach ([[0, 1, self::PAGE_ONE], [1, 2, self::PAGE_TWO], [2, 2, 'curto']] as [$index, $page, $content]) {
            DB::table('knowledge_chunks')->insert(['document_id' => $document->id, 'chunk_index' => $index, 'section' => 'INDICAÇÕES', 'page_start' => $page, 'page_end' => $page, 'content' => $content, 'embedding_model' => 'bge-m3', 'embedding_dimensions' => 8, 'embedding_content_hash' => hash('sha256', $content), 'created_at' => now(), 'updated_at' => now()]);
            $ids[] = (int) DB::getPdo()->lastInsertId('knowledge_chunks_id_seq');
        }
        DB::statement("update knowledge_chunks set embedding = '{$vector}'::vector where document_id = ?", [$document->id]);

        $this->app->instance(PdfTextExtractor::class, new class implements PdfTextExtractor
        {
            public function extract(string $absolutePath): ExtractedPdf
            {
                return new ExtractedPdf([new ExtractedPage(1, AuditApiTest::pageOne()), new ExtractedPage(2, AuditApiTest::pageTwo())]);
            }
        });

        return [$document, $ids];
    }

    public static function pageOne(): string
    {
        return self::PAGE_ONE;
    }

    public static function pageTwo(): string
    {
        return self::PAGE_TWO;
    }

    public function test_audit_endpoints_need_a_valid_api_token(): void
    {
        foreach (['tcc', 'pipeline', 'embeddings', 'quality', 'documents', 'documents/1/pdf', 'readability', 'evaluations'] as $path) {
            $this->getJson("/api/v1/audit/{$path}")->assertUnauthorized();
        }
        [, $ingest] = IngestionToken::issue('ingestao', IngestionToken::SCOPE_KNOWLEDGE_INGEST, now()->addDay());
        $this->withToken($ingest)->getJson('/api/v1/audit/tcc')->assertUnauthorized();
    }

    public function test_embedding_audit_reports_coverage_integrity_and_flags_a_stale_or_missing_vector(): void
    {
        [$document, $ids] = $this->documentWithChunks();
        DB::table('knowledge_chunks')->where('id', $ids[1])->update(['content' => 'texto alterado depois de indexado']);
        DB::table('knowledge_chunks')->insert(['document_id' => $document->id, 'chunk_index' => 3, 'page_start' => 2, 'page_end' => 2, 'content' => 'trecho sem vetor algum, ainda por indexar', 'created_at' => now(), 'updated_at' => now()]);

        $report = $this->withToken($this->token())->getJson('/api/v1/audit/embeddings?sample=5')->assertOk()->json();

        $this->assertSame(4, $report['coverage']['chunks']);
        $this->assertSame(3, $report['coverage']['embedded']);
        $this->assertSame(1, $report['coverage']['missing']);
        $this->assertSame([['model' => 'bge-m3', 'dimensions' => 8, 'chunks' => 3]], $report['coverage']['by_model']);
        $checks = collect($report['checks'])->keyBy('key');
        $this->assertFalse($checks['chunks_without_embedding']['ok']);
        $this->assertSame([$ids[2] + 1], $checks['chunks_without_embedding']['examples']);
        $this->assertSame(1, $checks['content_hash_current']['violations']);
        $this->assertTrue($checks['single_model']['ok']);
        $this->assertTrue($checks['dimension_matches_declared']['ok']);
        $this->assertEquals(1.0, $report['norms']['max']);
        $this->assertSame(3, $report['coherence']['sample']);
        $this->assertNull($report['live']);
    }

    public function test_quality_audit_finds_tiny_chunks_missing_ingredient_and_answers_without_evidence(): void
    {
        $this->documentWithChunks();
        MedicationQuestionFactory::new()->create(['status' => 'answered', 'evidences' => [], 'answer' => 'Resposta sem fonte.']);

        $report = $this->withToken($this->token())->getJson('/api/v1/audit/quality')->assertOk()->json();
        $checks = collect($report['groups'])->flatten(1)->keyBy('key');

        $this->assertFalse($checks['chunk_not_empty']['ok']);
        $this->assertSame(1, $checks['chunk_not_empty']['violations']);
        $this->assertFalse($checks['ready_without_ingredient']['ok']);
        $this->assertTrue($checks['ready_pdf_stored']['ok']);
        $this->assertTrue($checks['ready_without_chunks']['ok']);
        $this->assertFalse($checks['answered_has_evidence']['ok']);
        $this->assertGreaterThanOrEqual(3, $report['summary']['errors']);
        $this->assertArrayNotHasKey('pdf_hash_matches', $checks->all());

        $verified = $this->withToken($this->token())->getJson('/api/v1/audit/quality?verify_files=1')->assertOk()->json();
        $this->assertTrue(collect($verified['groups']['documents'])->firstWhere('key', 'pdf_hash_matches')['ok']);
    }

    public function test_citation_fidelity_detects_a_cited_chunk_that_no_longer_exists_and_a_text_not_in_the_chunk(): void
    {
        [, $ids] = $this->documentWithChunks();
        $evidence = fn (int $chunk, string $excerpt): array => ['chunk_id' => $chunk, 'excerpt' => $excerpt, 'supports_claims' => [0]];
        MedicationQuestionFactory::new()->create(['status' => 'answered', 'evidences' => [
            $evidence($ids[0], 'úlceras gástricas e duodenais'),
            $evidence($ids[1], 'frase que não existe no trecho'),
            $evidence(999999, 'trecho apagado'),
        ]]);

        $citations = $this->withToken($this->token())->getJson('/api/v1/audit/quality')->json('citations');

        $this->assertSame(3, $citations['evidences_checked']);
        $this->assertSame(1, $citations['chunk_missing']);
        $this->assertSame(1, $citations['excerpt_not_in_chunk']);
        $this->assertSame(0.3333, $citations['faithful']['rate']);
    }

    public function test_pdf_endpoint_reads_the_stored_file_and_compares_it_with_the_indexed_chunks(): void
    {
        [$document] = $this->documentWithChunks();
        $token = $this->token();

        $listing = $this->withToken($token)->getJson('/api/v1/audit/documents')->assertOk()->json('documents.0');
        $this->assertTrue($listing['pdf_stored']);
        $this->assertTrue($listing['size_matches_record']);
        $this->assertSame(3, $listing['chunks']);

        $report = $this->withToken($token)->getJson("/api/v1/audit/documents/{$document->id}/pdf")->assertOk()->json();
        $this->assertSame('ok', $report['status']);
        $this->assertTrue($report['file_hash_matches']);
        $this->assertSame(2, $report['pages']['count']);
        $this->assertSame('pdf_text', $report['extraction']['method']);
        $this->assertSame(3, $report['index_coverage']['chunks']);
        $this->assertSame(1.0, $report['index_coverage']['words_in_declared_pages_mean'] > 0.6 ? 1.0 : 0.0);
        $this->assertSame(0, $report['index_coverage']['chunks_with_page_out_of_range']);
        $this->assertArrayNotHasKey('text', $report);

        $withText = $this->withToken($token)->getJson("/api/v1/audit/documents/{$document->id}/pdf?text=1&page=2")->assertOk()->json('text');
        $this->assertCount(1, $withText);
        $this->assertStringContainsString('reações adversas', $withText[0]['text']);

        $this->withToken($token)->getJson('/api/v1/audit/documents/999/pdf')->assertNotFound();
    }

    public function test_pipeline_timings_summarise_each_ingestion_step(): void
    {
        [$document] = $this->documentWithChunks();
        foreach ([['embed', 1000], ['embed', 3000], ['chunk', 200]] as [$step, $ms]) {
            KnowledgeProcessingEvent::query()->create(['document_id' => $document->id, 'step' => $step, 'state' => 'done', 'started_at' => now()->subSeconds(10), 'finished_at' => now(), 'duration_ms' => $ms]);
        }
        KnowledgeProcessingEvent::query()->create(['document_id' => $document->id, 'step' => 'embed', 'state' => 'failed', 'started_at' => now(), 'finished_at' => now(), 'duration_ms' => 50, 'detail' => ['error_code' => 'AI_TIMEOUT']]);

        $report = $this->withToken($this->token())->getJson('/api/v1/audit/pipeline')->assertOk()->json();
        $embed = collect($report['steps'])->firstWhere('step', 'embed');

        $this->assertSame(3, $embed['runs']);
        $this->assertSame(1, $embed['failed']);
        $this->assertEquals(3000, $embed['max_ms']);
        $this->assertSame(1, $report['documents']['with_timeline']);
        $this->assertSame(3, $report['documents']['chunks_indexed']);
        $this->assertSame('AI_TIMEOUT', $report['failures'][0]['error_code']);
    }

    public function test_readability_uses_flesch_for_portuguese_and_compares_answers_with_their_sources(): void
    {
        $service = new ReadabilityService;
        $easy = $service->flesch('O remédio ajuda. Tome com água.');
        $hard = $service->flesch('A administração concomitante de inibidores seletivos da recaptação serotoninérgica potencializa significativamente manifestações extrapiramidais.');

        $this->assertGreaterThan(75, $easy['index']);
        $this->assertSame('muito fácil', $easy['band']);
        $this->assertLessThan(0, $hard['index']);
        $this->assertSame('muito difícil', $service->band($hard['index']));
        $this->assertNull($service->flesch('')['index']);

        MedicationQuestionFactory::new()->create(['status' => 'answered', 'answer' => 'O remédio ajuda a tratar o estômago. Tome de manhã.', 'evidences' => [['chunk_id' => 1, 'excerpt' => 'A administração concomitante potencializa significativamente manifestações extrapiramidais.', 'supports_claims' => [0]]]]);
        MedicationQuestionFactory::new()->create(['status' => 'answered', 'answer' => 'Resposta sem citação válida.', 'evidences' => [['chunk_id' => 1, 'excerpt' => 'Texto não usado.', 'supports_claims' => []]]]);

        $report = $this->withToken($this->token())->getJson('/api/v1/audit/readability')->assertOk()->json();

        $this->assertSame(1, $report['pairs']);
        $this->assertGreaterThan(0, $report['delta']['median']);
        $this->assertSame(1, $report['easier_than_source']['n']);
    }

    public function test_wilson_interval_matches_the_reference_value_and_handles_empty_samples(): void
    {
        $this->assertSame(['rate' => 0.8, 'low' => 0.4902, 'high' => 0.9433, 'n' => 10], Metrics::wilson(8, 10));
        $this->assertSame(['rate' => null, 'low' => null, 'high' => null, 'n' => 0], Metrics::wilson(0, 0));
        $this->assertSame(1.0, Metrics::wilson(5, 5)['high']);
    }

    public function test_tcc_dossier_gathers_every_block_and_lists_the_experiments(): void
    {
        $this->documentWithChunks();
        MedicationQuestionFactory::new()->create(['status' => 'answered']);

        $dossier = $this->withToken($this->token())->getJson('/api/v1/audit/tcc')->assertOk()->json();

        foreach (['environment', 'knowledge_base', 'usage', 'grounding', 'ingestion_timings', 'embeddings', 'data_quality', 'readability', 'experiments'] as $block) {
            $this->assertArrayHasKey($block, $dossier);
        }
        $this->assertSame(1, $dossier['usage']['questions']['total']);
        $this->assertSame(1, $dossier['knowledge_base']['documents']['ready']);
        $this->assertSame(['identificacao', 'interacoes', 'recuperacao', 'geracao', 'sem_recuperacao'], array_column($dossier['experiments'], 'key'));
        $this->assertSame(3, $dossier['embeddings']['coverage']['chunks']);
        $this->assertStringNotContainsString('"email"', json_encode($dossier));
    }

    public function test_a_block_that_fails_reports_its_reason_and_the_rest_of_the_dossier_still_answers(): void
    {
        [, $ids] = $this->documentWithChunks();
        MedicationQuestionFactory::new()->create(['status' => 'answered', 'evidences' => ['texto solto de uma resposta antiga', ['chunk_id' => $ids[0], 'excerpt' => 'úlceras gástricas e duodenais']]]);
        DB::statement('drop table knowledge_processing_events');

        $dossier = $this->withToken($this->token())->getJson('/api/v1/audit/tcc')->assertOk()->json();

        $this->assertSame('QueryException', $dossier['ingestion_timings']['error']['exception']);
        $this->assertStringContainsString('knowledge_processing_events', $dossier['ingestion_timings']['error']['message']);
        $this->assertSame(1, $dossier['knowledge_base']['documents']['ready']);
        $this->assertSame(1, $dossier['data_quality']['citations']['evidences_checked']);
        $this->assertSame([], $dossier['data_quality']['failed_groups']);
    }

    public function test_evaluation_registry_validates_the_task_filter(): void
    {
        $token = $this->token();

        $this->withToken($token)->getJson('/api/v1/audit/evaluations?task=identification')->assertOk()->assertJsonStructure(['experiments', 'runs']);
        $this->withToken($token)->getJson('/api/v1/audit/evaluations?task=invalida')->assertUnprocessable();
        $this->assertSame(0, MedicationQuestion::query()->count());
    }
}
