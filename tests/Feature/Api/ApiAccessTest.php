<?php

namespace Tests\Feature\Api;

use App\Data\Ai\TextGenerationRequest;
use App\Data\Ai\TextGenerationResponse;
use App\Models\AiUsageLog;
use App\Models\Consultation\MedicationQuestion;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\IngestionToken;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\TextGenerationProvider;
use App\Services\Evaluation\EvaluationAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** API /api/v1 (docs/api.md): token com escopo e revogável, estatísticas sem identificar pessoas e avaliação do Perguntar. */
final class ApiAccessTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $scope = IngestionToken::SCOPE_API_READ, mixed $expiresAt = null): array
    {
        return IngestionToken::issue('agente', $scope, $expiresAt ?? now()->addDay());
    }

    public function test_only_a_valid_api_token_opens_the_api(): void
    {
        [, $ingest] = $this->token(IngestionToken::SCOPE_KNOWLEDGE_INGEST);
        [, $expired] = $this->token(expiresAt: now()->subMinute());
        [$revokedToken, $revoked] = $this->token();
        $revokedToken->revoke();
        [$token, $plain] = $this->token();

        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken('nao-existe')->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($ingest)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($expired)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($revoked)->getJson('/api/v1/me')->assertUnauthorized();

        $this->withToken($plain)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('token.id', $token->id)
            ->assertJsonPath('token.scope', IngestionToken::SCOPE_API_READ);
        $this->assertNotNull($token->fresh()->last_used_at);
    }

    public function test_a_read_token_cannot_evaluate_but_an_evaluation_token_can_read(): void
    {
        [, $read] = $this->token();
        [, $evaluate] = $this->token(IngestionToken::SCOPE_API_EVALUATE);

        $this->withToken($read)->postJson('/api/v1/evaluation/ask', ['question' => 'Para que serve a dipirona?'])->assertUnauthorized();
        $this->withToken($evaluate)->getJson('/api/v1/stats/overview')->assertOk();
    }

    public function test_stats_return_the_dashboard_numbers_without_names_or_emails(): void
    {
        $patient = User::factory()->create(['name' => 'Ana Paciente', 'email' => 'ana@example.test']);
        AiUsageLog::factory()->create(['user_id' => $patient->id, 'input_tokens' => 1000, 'output_tokens' => 100, 'estimated_cost_usd' => 0.003]);
        [, $plain] = $this->token();

        $this->withToken($plain)->getJson('/api/v1/stats/overview')
            ->assertOk()
            ->assertJsonStructure(['period', 'consultations', 'pairs', 'questions', 'leaflets', 'ai' => ['calls', 'estimated_cost_usd'], 'timeline']);
        $usage = $this->withToken($plain)->getJson('/api/v1/stats/ai-usage?from='.now()->subDays(2)->toDateString())
            ->assertOk()
            ->assertJsonPath('users.0.user_id', $patient->id)
            ->assertJsonPath('users.0.calls', 1)
            ->assertJsonPath('users_total', 1);
        $this->assertStringNotContainsString('ana@example.test', $usage->getContent());
        $this->assertStringNotContainsString('Ana Paciente', $usage->getContent());
        $this->withToken($plain)->getJson('/api/v1/stats/overview?from=09/11/2026')->assertUnprocessable();
    }

    public function test_questions_list_and_trace_each_answer(): void
    {
        $ana = User::factory()->create();
        $other = User::factory()->create();
        $question = MedicationQuestion::query()->create([
            'uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'user_id' => $ana->id, 'medicine_name' => 'DIPIRONA', 'question' => 'Dipirona serve para dor de cabeça?',
            'status' => 'answered', 'answer' => 'A dipirona alivia a dor.', 'evidences' => [['chunk_id' => 1]], 'generation' => ['status' => 'accepted', 'claims_accepted' => 1, 'claims_rejected' => 0],
            'retrieval' => ['classification' => ['primary' => 'INDICATION', 'intents' => ['INDICATION']], 'sufficiency' => ['verdict' => 'sufficient']], 'expires_at' => now()->addDays(30),
        ]);
        MedicationQuestion::query()->create(['uuid' => 'aaaaaaaa-0000-4000-8000-000000000002', 'user_id' => $other->id, 'medicine_name' => 'X', 'question' => 'Outra?', 'status' => 'no_leaflet', 'expires_at' => now()->addDays(30)]);
        [, $plain] = $this->token();

        $this->withToken($plain)->getJson("/api/v1/questions?user_id={$ana->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.uuid', $question->uuid)
            ->assertJsonPath('data.0.primary_intent', 'INDICATION')
            ->assertJsonPath('data.0.claims_accepted', 1);
        $this->withToken($plain)->getJson("/api/v1/questions/{$question->uuid}")
            ->assertOk()
            ->assertJsonPath('answer', 'A dipirona alivia a dor.')
            ->assertJsonPath('retrieval.sufficiency.verdict', 'sufficient');
        $this->withToken($plain)->getJson('/api/v1/questions/nao-existe')->assertNotFound();
    }

    public function test_evaluation_runs_the_real_pipeline_as_the_evaluation_account_and_scores_the_answer(): void
    {
        $this->scriptedProvider('{"coverage":"full","segments":[{"kind":"fact","text":"A dipirona alivia a dor, inclusive a dor de cabeça.","evidence_ids":["ALL"]}]}');
        $this->dipirona();
        [, $plain] = $this->token(IngestionToken::SCOPE_API_EVALUATE);

        $response = $this->withToken($plain)->postJson('/api/v1/evaluation/ask', [
            'question' => 'Dipirona serve para dor de cabeça?',
            'scenario' => 'indicacao',
            'expects' => ['facts' => true, 'asks' => false],
        ])->assertOk();

        $response->assertJsonPath('status', 'answered')
            ->assertJsonPath('answer', 'A dipirona alivia a dor, inclusive a dor de cabeça.')
            ->assertJsonPath('evaluation.scenario', 'indicacao')
            ->assertJsonPath('evaluation.score.scores.relevancia', true)
            ->assertJsonPath('evaluation.score.scores.ancoragem', true)
            ->assertJsonPath('retrieval.classification.primary', 'INDICATION')
            ->assertJsonPath('next_conversation_turn.question', 'Dipirona serve para dor de cabeça?');
        $stored = MedicationQuestion::query()->where('uuid', $response->json('uuid'))->firstOrFail();
        $account = User::query()->where('email', EvaluationAccount::EMAIL)->firstOrFail();
        $this->assertSame($account->id, $stored->user_id, 'a pergunta de teste fica na conta de avaliação, separada dos pacientes');
        $this->assertFalse($account->isActive(), 'a conta de avaliação não entra no sistema');
        $this->withToken($plain)->getJson('/api/v1/me')->assertJsonPath('evaluation_user_id', $account->id);
    }

    public function test_evaluation_validates_input_and_is_rate_limited_per_token(): void
    {
        [, $plain] = $this->token(IngestionToken::SCOPE_API_EVALUATE);

        $this->withToken($plain)->postJson('/api/v1/evaluation/ask', ['question' => 'Serve?', 'expects' => ['inventado' => true]])->assertUnprocessable();
        for ($attempt = 2; $attempt <= 6; $attempt++) {
            $this->withToken($plain)->postJson('/api/v1/evaluation/ask', [])->assertUnprocessable();
        }
        $this->withToken($plain)->postJson('/api/v1/evaluation/ask', [])->assertTooManyRequests();

        [, $another] = $this->token(IngestionToken::SCOPE_API_EVALUATE);
        $this->withToken($another)->postJson('/api/v1/evaluation/ask', [])->assertUnprocessable();
    }

    public function test_the_revoke_command_cuts_access_immediately(): void
    {
        [$token, $plain] = $this->token();
        $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

        $this->artisan('tokens:revoke', ['id' => $token->id])->assertSuccessful();

        $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
    }

    private function scriptedProvider(string $content): void
    {
        config(['bulai.generation.enabled' => true, 'ai.text.default' => 'scripted', 'bulai.retrieval.mode' => 'lexical']);
        $manager = new AiProviderManager;
        $manager->extend(AiProviderManager::TEXT, 'scripted', fn () => new class($content) implements TextGenerationProvider
        {
            public function __construct(private readonly string $content) {}

            public function generate(TextGenerationRequest $request): TextGenerationResponse
            {
                preg_match_all('/<evidencia id="([^"]+)"/', $request->prompt, $ids);

                return new TextGenerationResponse(str_replace('["ALL"]', (string) json_encode(array_values(array_diff($ids[1], ['T1']))), $this->content), 'scripted', null);
            }
        });
        $this->app->instance(AiProviderManager::class, $manager);
    }

    private function dipirona(): void
    {
        $medicine = AnvisaMedicine::factory()->create(['product_name' => 'DIPIRONA', 'normalized_product_name' => 'dipirona', 'active_ingredient' => 'DIPIRONA', 'normalized_active_ingredient' => 'dipirona']);
        $ingredient = ActiveIngredient::factory()->create(['canonical_name' => 'DIPIRONA', 'normalized_name' => 'dipirona']);
        $medicine->ingredients()->attach($ingredient->id, ['component_text' => 'DIPIRONA', 'method' => 'alias', 'confidence' => 1]);
        $source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'type' => 'document', 'active' => true]);
        $document = KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => 'dipirona', 'title' => 'DIPIRONA — Bula', 'document_type' => 'bula_paciente', 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'PENDING', 'content_hash' => 'h-dipirona', 'sha256' => hash('sha256', 'dipirona')]);
        $document->chunks()->create(['chunk_index' => 0, 'section' => 'PARA QUE ESTE MEDICAMENTO É INDICADO?', 'page_start' => 2, 'page_end' => 2, 'content' => 'A dipirona é indicada como analgésico, para o alívio da dor, inclusive dor de cabeça, e como antitérmico, para reduzir a febre.', 'metadata' => []]);
        DB::table('knowledge_document_ingredients')->insert(['document_id' => $document->id, 'ingredient_id' => $ingredient->id, 'method' => 'registration', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
}
