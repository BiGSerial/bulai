<?php

namespace Tests\Feature\Knowledge;

use App\Models\Knowledge\IngestionToken;
use App\Models\Knowledge\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeImportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_token_imports_document_and_chunks_without_pdf(): void
    {
        [, $plainToken] = IngestionToken::issue('test token', IngestionToken::SCOPE_KNOWLEDGE_INGEST, now()->addDay());

        $response = $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/import', $this->validPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('external_key', 'anvisa_ibuprofeno_bula_2026')
            ->assertJsonPath('content_status', 'READY')
            ->assertJsonPath('file_status', 'PENDING')
            ->assertJsonPath('embedding_status', 'PENDING')
            ->assertJsonPath('chunks_count', 1);

        $document = KnowledgeDocument::query()->where('external_key', 'anvisa_ibuprofeno_bula_2026')->firstOrFail();

        $this->assertNull($document->storage_path);
        $this->assertSame('Indicações', $document->chunks()->firstOrFail()->section);
    }

    public function test_expired_token_is_rejected(): void
    {
        [, $plainToken] = IngestionToken::issue('expired', IngestionToken::SCOPE_KNOWLEDGE_INGEST, now()->subMinute());

        $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/import', $this->validPayload())
            ->assertUnauthorized();
    }

    public function test_revoked_token_is_rejected(): void
    {
        [$token, $plainToken] = IngestionToken::issue('revoked', IngestionToken::SCOPE_KNOWLEDGE_INGEST, now()->addDay());
        $token->forceFill(['revoked_at' => now()])->save();

        $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/import', $this->validPayload())
            ->assertUnauthorized();
    }

    public function test_scope_mismatch_is_rejected(): void
    {
        [, $plainToken] = IngestionToken::issue('wrong scope', 'admin:*', now()->addDay());

        $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/import', $this->validPayload())
            ->assertUnauthorized();
    }

    public function test_invalid_payload_is_rejected(): void
    {
        [, $plainToken] = IngestionToken::issue('test token', IngestionToken::SCOPE_KNOWLEDGE_INGEST, now()->addDay());

        $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/import', ['external_key' => 'missing-fields'])
            ->assertUnprocessable();
    }

    public function test_external_key_import_updates_document_and_replaces_chunks(): void
    {
        [, $plainToken] = IngestionToken::issue('test token', IngestionToken::SCOPE_KNOWLEDGE_INGEST, now()->addDay());

        $this->withToken($plainToken)->postJson('/api/internal/knowledge/import', $this->validPayload())->assertCreated();

        $payload = $this->validPayload();
        $payload['title'] = 'Bula Profissional - Ibuprofeno Revisada';
        $payload['chunks'] = [
            [
                'index' => 0,
                'section' => 'Contraindicações',
                'content' => 'Não utilizar em caso de hipersensibilidade ao ibuprofeno.',
            ],
        ];

        $this->withToken($plainToken)->postJson('/api/internal/knowledge/import', $payload)->assertCreated();

        $this->assertSame(1, KnowledgeDocument::query()->count());

        $document = KnowledgeDocument::query()->firstOrFail();
        $this->assertSame('Bula Profissional - Ibuprofeno Revisada', $document->title);
        $this->assertSame('Contraindicações', $document->chunks()->firstOrFail()->section);
    }

    private function validPayload(): array
    {
        return [
            'external_key' => 'anvisa_ibuprofeno_bula_2026',
            'title' => 'Bula Profissional - Ibuprofeno',
            'document_type' => 'bula',
            'language' => 'pt-BR',
            'source' => [
                'name' => 'ANVISA',
                'organization' => 'Agência Nacional de Vigilância Sanitária',
            ],
            'metadata' => [
                'drug' => 'Ibuprofeno',
                'agency' => 'ANVISA',
            ],
            'chunks' => [
                [
                    'index' => 0,
                    'section' => 'Indicações',
                    'page_start' => 1,
                    'page_end' => 2,
                    'content' => 'Ibuprofeno é indicado para redução de febre e alívio de dores leves a moderadas.',
                ],
            ],
        ];
    }
}
