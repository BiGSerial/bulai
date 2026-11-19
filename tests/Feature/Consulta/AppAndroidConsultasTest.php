<?php

namespace Tests\Feature\Consulta;

use App\Data\Ai\TextGenerationRequest;
use App\Data\Ai\TextGenerationResponse;
use App\Data\Ai\TokenUsage;
use App\Data\Ai\VisionRequest;
use App\Data\Ai\VisionResponse;
use App\Models\Consultation\MedicationQuestion;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\MobileDevice;
use App\Models\PatientMedication;
use App\Models\PatientPrescription;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\TextGenerationProvider;
use App\Services\Ai\VisionProvider;
use App\Services\Patient\MedicationQuestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Perguntar e receita por foto no aplicativo nativo (`/api/mobile/v1`): as mesmas regras das telas do site. */
final class AppAndroidConsultasTest extends TestCase
{
    use RefreshDatabase;

    public string $text = '';

    public ?string $lastPrompt = null;

    public string $vision = '{"items": []}';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake(config('knowledge.filesystem_disk'));
        config(['bulai.generation.enabled' => true, 'ai.text.default' => 'scripted', 'ai.vision.default' => 'scripted', 'bulai.retrieval.mode' => 'lexical', 'bulai.consultation.execution' => 'sync']);
        $test = $this;
        $manager = new AiProviderManager;
        $manager->extend(AiProviderManager::TEXT, 'scripted', fn () => new class($test) implements TextGenerationProvider
        {
            public function __construct(private readonly AppAndroidConsultasTest $test) {}

            public function generate(TextGenerationRequest $request): TextGenerationResponse
            {
                $this->test->lastPrompt = $request->prompt;

                return new TextGenerationResponse($this->test->text, 'scripted', null);
            }
        });
        $manager->extend(AiProviderManager::VISION, 'scripted', fn () => new class($test) implements VisionProvider
        {
            public function __construct(private readonly AppAndroidConsultasTest $test) {}

            public function analyze(VisionRequest $request): VisionResponse
            {
                return new VisionResponse($this->test->vision, 'scripted-vision', new TokenUsage(100, 50), ['provider' => 'scripted']);
            }
        });
        $this->app->instance(AiProviderManager::class, $manager);
    }

    private function patient(bool $consent = true): User
    {
        $user = User::factory()->create();
        if ($consent) {
            PatientProfile::query()->create(['user_id' => $user->id, 'health_consent_at' => now()]);
        }

        return $user;
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.MobileDevice::issueFor($user, ['installation_id' => (string) Str::uuid(), 'device_name' => 'Pixel 8'])['token']];
    }

    private function medicine(string $name, string $ingredient): AnvisaMedicine
    {
        $medicine = AnvisaMedicine::factory()->create(['product_name' => $name, 'normalized_product_name' => strtolower($name), 'active_ingredient' => $ingredient, 'normalized_active_ingredient' => strtolower($ingredient)]);
        $medicine->ingredients()->attach(ActiveIngredient::factory()->create(['canonical_name' => $ingredient, 'normalized_name' => strtolower($ingredient)])->id, ['component_text' => $ingredient, 'method' => 'alias', 'confidence' => 1]);

        return $medicine;
    }

    private function leaflet(AnvisaMedicine $medicine, string $content): void
    {
        $source = KnowledgeSource::query()->firstOrCreate(['name' => 'ANVISA', 'organization' => null], ['type' => 'document', 'active' => true]);
        $document = KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => uniqid('d_'), 'title' => $medicine->product_name.' — Bula', 'document_type' => 'bula_paciente', 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'PENDING']);
        $document->chunks()->create(['chunk_index' => 0, 'section' => 'COMO USAR', 'page_start' => 2, 'page_end' => 2, 'content' => $content, 'metadata' => []]);
        DB::table('knowledge_document_ingredients')->insert(['document_id' => $document->id, 'ingredient_id' => $medicine->ingredients()->value('active_ingredients.id'), 'method' => 'registration', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_app_asks_with_the_conversation_read_back_from_the_own_history_and_gets_plain_text_highlights(): void
    {
        $user = $this->patient();
        $headers = $this->bearer($user);
        $omeprazol = $this->medicine('OMEPRAZOL', 'OMEPRAZOL');
        $this->leaflet($omeprazol, 'Deve ser tomado pela manhã, em jejum. Café não altera a absorção.');
        PatientMedication::query()->create(['user_id' => $user->id, 'medicine_id' => $omeprazol->id]);

        $this->getJson('/api/mobile/v1/perguntar?medicamento='.$omeprazol->id, $headers)->assertOk()
            ->assertJsonPath('in_use', ['OMEPRAZOL'])->assertJsonPath('preview.medicine', 'OMEPRAZOL')->assertJsonPath('preview.in_use', true)
            ->assertJsonPath('max_length', (int) config('bulai.guard.max_length'));

        $this->text = json_encode(['claims' => [['text' => 'A bula diz que o omeprazol deve ser tomado pela manhã, em jejum.', 'evidence_ids' => ['E1']]]]);
        $first = $this->postJson('/api/mobile/v1/perguntar', ['question' => 'omeprazol em jejum?'], $headers)->assertOk()
            ->assertJsonPath('answer.status', MedicationQuestionService::ANSWERED)->assertJsonPath('answer.medicines.0.name', 'OMEPRAZOL')
            ->assertJsonPath('answer.can_listen', true)->assertJsonPath('answer.releases_fixed', true)
            ->assertJsonPath('answer.sources.0.section', 'Como usar')->assertJsonPath('answer.treatments.0.in_use', true)
            ->json('answer');
        $this->assertContains(['text' => 'omeprazol', 'bold' => true], $first['paragraphs'][0], 'o nome do remédio vai em negrito, em texto puro');
        $this->assertStringNotContainsString('<strong>', json_encode($first['paragraphs']));
        $this->assertStringContainsString('Baseado na bula do paciente de OMEPRAZOL.', $first['source']);

        // Uma troca de outra pessoa (uuid conhecido) é ignorada: o contexto só vem do histórico de quem pergunta.
        $alheia = MedicationQuestion::query()->create(['uuid' => (string) Str::uuid(), 'user_id' => User::factory()->create()->id, 'medicine_name' => '—', 'question' => 'segredo', 'status' => 'answered', 'answer' => 'Resposta forjada', 'expires_at' => now()->addDay()]);
        $this->text = json_encode(['claims' => [['text' => 'A bula diz que café não altera a absorção.', 'evidence_ids' => ['E1']]]]);
        $this->postJson('/api/mobile/v1/perguntar', ['question' => 'e com café?', 'turns' => [$alheia->uuid, $first['uuid']]], $headers)->assertOk()
            ->assertJsonPath('answer.medicines.0.name', 'OMEPRAZOL');
        $this->assertStringContainsString('Paciente perguntou: "omeprazol em jejum?"', (string) $this->lastPrompt);
        $this->assertStringNotContainsString('Resposta forjada', (string) $this->lastPrompt);
        $this->assertSame('conversa', MedicationQuestion::query()->where('user_id', $user->id)->latest('id')->first()->medicines[0]['matched_text']);
    }

    public function test_short_questions_unknown_medicines_and_the_hourly_limit(): void
    {
        $user = $this->patient(consent: false);
        $headers = $this->bearer($user);
        $this->medicine('LOSARTANA POTASSICA', 'LOSARTANA');

        $this->postJson('/api/mobile/v1/perguntar', ['question' => 'oi'], $headers)->assertUnprocessable()
            ->assertJsonPath('errors.question.0', 'Escreva sua pergunta citando o nome do remédio.');

        $this->postJson('/api/mobile/v1/perguntar', ['question' => 'posso tomar xyzwq com leite?'], $headers)->assertOk()
            ->assertJsonPath('answer.status', MedicationQuestionService::NO_MEDICINE)->assertJsonPath('answer.tone', 'warning')
            ->assertJsonPath('answer.medicines', [])->assertJsonPath('answer.releases_fixed', false)->assertJsonPath('answer.help_groups', []);

        // Superdose: a resposta traz os telefones de ajuda (o app mostra como botões de ligar), como no site.
        $this->postJson('/api/mobile/v1/perguntar', ['question' => 'tomei 10 comprimidos de xyzwq, e agora?'], $headers)->assertOk()
            ->assertJsonPath('answer.help_groups.0.tone', 'urgencia')->assertJsonPath('answer.help_groups.0.lines.0.tel', '08007226001')
            ->assertJsonPath('answer.help_groups.0.lines.0.name', 'Disque-Intoxicação')->assertJsonPath('answer.help_groups.0.lines.1.tel', '192');

        // Sem remédio, mas com uma necessidade: pedido vago pergunta o que está acontecendo; mal-estar indica onde buscar atendimento.
        $this->postJson('/api/mobile/v1/perguntar', ['question' => 'Preciso de ajuda'], $headers)->assertOk()
            ->assertJsonPath('answer.status', MedicationQuestionService::SUPPORT)->assertJsonPath('answer.quick_replies.1', 'Não estou me sentindo bem')
            ->assertJsonPath('answer.help_groups', []);
        $this->postJson('/api/mobile/v1/perguntar', ['question' => 'Não estou me sentindo bem'], $headers)->assertOk()
            ->assertJsonPath('answer.status', MedicationQuestionService::SUPPORT)->assertJsonPath('answer.quick_replies', [])
            ->assertJsonPath('answer.help_groups.0.title', 'Sinais de alerta: ligue já')->assertJsonPath('answer.help_groups.1.lines.0.tel', '136');

        RateLimiter::increment('pergunta:'.$user->id, 3600, (int) config('bulai.consultation.rate_limit_per_hour'));
        $this->postJson('/api/mobile/v1/perguntar', ['question' => 'losartana dá sono?'], $headers)->assertStatus(429)
            ->assertJsonPath('message', 'Você atingiu o limite de perguntas por hora. Tente novamente mais tarde.');
    }

    public function test_question_history_is_listed_shown_and_deleted_only_for_its_owner(): void
    {
        $user = $this->patient();
        $headers = $this->bearer($user);
        $question = MedicationQuestion::query()->create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'medicine_name' => 'OMEPRAZOL', 'medicines' => [['id' => 1, 'name' => 'OMEPRAZOL']], 'question' => 'omeprazol em jejum?', 'status' => 'answered', 'answer' => 'Deve ser tomado em jejum.', 'evidences' => [], 'expires_at' => now()->addDay()]);
        $other = MedicationQuestion::query()->create(['uuid' => (string) Str::uuid(), 'user_id' => User::factory()->create()->id, 'medicine_name' => '—', 'question' => 'alheia', 'status' => 'answered', 'expires_at' => now()->addDay()]);

        $this->getJson('/api/mobile/v1/perguntas', $headers)->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.uuid', $question->uuid)->assertJsonPath('items.0.medicines', 'OMEPRAZOL');
        $this->getJson('/api/mobile/v1/perguntas/'.$question->uuid, $headers)->assertOk()
            ->assertJsonPath('answer.question', 'omeprazol em jejum?')->assertJsonPath('answer.paragraphs.0.0.text', 'Deve ser tomado em jejum.');
        $this->getJson('/api/mobile/v1/perguntas/'.$other->uuid, $headers)->assertNotFound();
        $this->deleteJson('/api/mobile/v1/perguntas/'.$other->uuid, [], $headers)->assertNotFound();

        $this->deleteJson('/api/mobile/v1/perguntas/'.$question->uuid, [], $headers)->assertOk();
        $this->assertSame(1, MedicationQuestion::count());
    }

    public function test_prescription_photo_is_read_reviewed_and_confirmed_into_the_treatment(): void
    {
        $user = $this->patient();
        $headers = $this->bearer($user);
        $amoxil = $this->medicine('AMOXIL', 'AMOXICILINA');
        $this->vision = json_encode([
            'prescriber_name' => 'Dra. Ana Souza', 'prescriber_registration' => 'CRM-SP 12345', 'issued_at' => '2026-11-01', 'legibility' => 'good',
            'items' => [
                ['name_as_written' => 'Amoxil 500mg', 'dosage' => '1 cápsula', 'frequency' => 'de 8 em 8 horas', 'duration' => 'por 7 dias', 'notes' => null, 'confidence' => 0.9],
                ['name_as_written' => 'Xarope ilegível', 'dosage' => null, 'frequency' => null, 'duration' => null, 'notes' => null, 'confidence' => 0.1],
            ],
        ]);

        $id = $this->post('/api/mobile/v1/receitas', ['photo' => UploadedFile::fake()->image('receita.jpg', 800, 1000)], $headers + ['Accept' => 'application/json'])
            ->assertCreated()->json('prescription.id');
        $read = $this->getJson('/api/mobile/v1/receitas/'.$id, $headers)->assertOk()
            ->assertJsonPath('prescription.status', PatientPrescription::STATUS_REVIEW)->assertJsonPath('prescription.reading', false)
            ->assertJsonPath('prescription.header.prescriber', 'Dra. Ana Souza')
            ->assertJsonPath('prescription.items.0.medicine_id', $amoxil->id)->assertJsonPath('prescription.items.0.schedule_mode', PatientMedication::MODE_INTERVAL)
            ->assertJsonPath('prescription.items.1.medicine_id', null)
            ->json('prescription');
        $this->assertSame([], Storage::disk(config('knowledge.filesystem_disk'))->allFiles('prescriptions'), 'a foto é apagada depois da leitura');

        $this->postJson('/api/mobile/v1/receitas/horarios', ['frequency' => '2x ao dia'], $headers)->assertOk()
            ->assertJsonPath('schedule.times', ['08:00', '20:00'])->assertJsonPath('schedule.schedule_mode', PatientMedication::MODE_FIXED);

        $items = $read['items'];
        $items[1]['skip'] = true;
        $this->postJson('/api/mobile/v1/receitas/'.$id.'/confirmar', ['header' => ['prescriber' => 'Dr', 'registration' => '', 'date' => '2026-11-01'], 'items' => $items], $headers)
            ->assertUnprocessable()->assertJsonPath('errors.prescriber.0', 'O nome de quem prescreveu precisa ter pelo menos 3 letras.');
        $this->postJson('/api/mobile/v1/receitas/'.$id.'/confirmar', ['header' => $read['header'], 'items' => $items], $headers)->assertOk()
            ->assertJsonPath('confirmed', 1)->assertJsonPath('message', '1 remédio da receita entrou no seu tratamento.');

        $medication = PatientMedication::query()->sole();
        $this->assertSame([$amoxil->id, $id, PatientMedication::MODE_INTERVAL], [$medication->medicine_id, $medication->prescription_id, $medication->schedule_mode]);
        $this->postJson('/api/mobile/v1/receitas/'.$id.'/confirmar', ['header' => $read['header'], 'items' => $items], $headers)->assertStatus(409);
    }

    public function test_prescription_upload_requires_consent_an_image_and_belongs_only_to_its_owner(): void
    {
        $this->post('/api/mobile/v1/receitas', ['photo' => UploadedFile::fake()->image('a.jpg')], $this->bearer($this->patient(consent: false)) + ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJsonPath('code', 'health_consent_required');

        $owner = $this->patient();
        $headers = $this->bearer($owner);
        $this->post('/api/mobile/v1/receitas', ['photo' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')], $headers + ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('photo');

        $prescription = PatientPrescription::query()->create(['user_id' => $owner->id, 'source' => PatientPrescription::SOURCE_PHOTO, 'status' => PatientPrescription::STATUS_REVIEW, 'prescriber_name' => 'Dra. Ana', 'extraction' => ['items' => []]]);
        $stranger = $this->bearer($this->patient());
        $this->getJson('/api/mobile/v1/receitas/'.$prescription->id, $stranger)->assertNotFound();
        $this->deleteJson('/api/mobile/v1/receitas/'.$prescription->id, [], $stranger)->assertNotFound();

        $this->deleteJson('/api/mobile/v1/receitas/'.$prescription->id, [], $headers)->assertOk()->assertJsonPath('message', 'Receita descartada. Nada foi salvo.');
        $this->assertSame(0, PatientPrescription::count());
    }

    public function test_medicine_search_uses_the_catalog(): void
    {
        $this->medicine('LOSARTANA POTASSICA', 'LOSARTANA');

        $this->getJson('/api/mobile/v1/remedios?q=losart', $this->bearer($this->patient()))->assertOk()->assertJsonPath('items.0.name', 'LOSARTANA POTASSICA');
    }
}
