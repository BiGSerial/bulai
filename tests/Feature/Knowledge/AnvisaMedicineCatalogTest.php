<?php

namespace Tests\Feature\Knowledge;

use App\Livewire\Admin\Knowledge\Pending\Review;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Knowledge\AnvisaMedicineCsvImporter;
use App\Services\Knowledge\AnvisaRegulatoryCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

final class AnvisaMedicineCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_latin1_catalog_with_traceability_and_lookup(): void
    {
        Storage::fake('local');
        $path = $this->catalogFile('catalog.csv', [
            ['MEDICAMENTO', 'ATROVENT', '28/03/1985', 'Novo', '103670004', '032035', '2599100347279', 'BRONCODILATADORES', '60831658000177 - BOEHRINGER INGELHEIM DO BRASIL QUÍMICA E FARMACÊUTICA LTDA.', 'Ativo', 'BROMETO DE IPRATRÓPIO'],
            ['MEDICAMENTO', 'PRODUTO SEM REGISTRO', '', 'Específico', '', '', '25351000000123456', '', 'EMPRESA FARMACÊUTICA LTDA.', 'Inativo', 'SUBSTÂNCIA TESTE'],
            ['MEDICAMENTO', '', '', '', '', '', '', '', '', '', ''],
        ]);

        $import = app(AnvisaMedicineCsvImporter::class)->import($path);

        $this->assertSame('COMPLETED', $import->status);
        $this->assertSame(3, $import->rows_read);
        $this->assertSame(2, $import->rows_inserted);
        $this->assertSame(1, $import->rows_rejected);
        $this->assertDatabaseCount('anvisa_medicines', 2);
        $record = app(AnvisaRegulatoryCatalog::class)->findByRegistrationNumber('1.0367.0004');
        $this->assertSame('ATROVENT', $record?->productName);
        $this->assertSame('BROMETO DE IPRATRÓPIO', $record?->activeIngredient);
        $this->assertSame($import->sha256, $record?->datasetSha256);
    }

    public function test_same_file_is_idempotent_and_new_dataset_updates_existing_registration(): void
    {
        Storage::fake('local');
        $firstPath = $this->catalogFile('first.csv', [['MEDICAMENTO', 'ATROVENT', '', 'Novo', '103670004', '', '2599', '', 'EMPRESA A', 'Ativo', 'BROMETO DE IPRATRÓPIO']]);
        $first = app(AnvisaMedicineCsvImporter::class)->import($firstPath);

        $same = app(AnvisaMedicineCsvImporter::class)->import($firstPath);

        $this->assertSame($first->id, $same->id);
        $this->assertDatabaseCount('anvisa_catalog_imports', 1);
        $updatedPath = $this->catalogFile('updated.csv', [['MEDICAMENTO', 'ATROVENT ATUALIZADO', '', 'Novo', '103670004', '', '2599', '', 'EMPRESA A', 'Inativo', 'BROMETO DE IPRATRÓPIO']]);
        $updated = app(AnvisaMedicineCsvImporter::class)->import($updatedPath);
        $this->assertSame(1, $updated->rows_updated);
        $this->assertDatabaseHas('anvisa_medicines', ['registration_number' => '103670004', 'product_name' => 'ATROVENT ATUALIZADO', 'registration_status' => 'Inativo']);
        $this->assertDatabaseCount('anvisa_medicines', 1);
    }

    public function test_invalid_registration_is_preserved_but_not_used_for_lookup(): void
    {
        Storage::fake('local');
        $path = $this->catalogFile('invalid.csv', [['MEDICAMENTO', 'PRODUTO ANTIGO', '', 'Similar', '125', '', '', '', 'EMPRESA A', 'Inativo', 'ATIVO A']]);

        app(AnvisaMedicineCsvImporter::class)->import($path);

        $medicine = AnvisaMedicine::query()->sole();
        $this->assertSame('125', $medicine->registration_number_original);
        $this->assertNull($medicine->registration_number);
        $this->assertNull(app(AnvisaRegulatoryCatalog::class)->findByRegistrationNumber('125'));
    }

    public function test_command_imports_catalog(): void
    {
        Storage::fake('local');
        $path = $this->catalogFile('command.csv', [['MEDICAMENTO', 'ATROVENT', '', 'Novo', '103670004', '', '', '', 'EMPRESA A', 'Ativo', 'BROMETO DE IPRATRÓPIO']]);

        $this->artisan('knowledge:import-anvisa-medicines', ['path' => $path])->expectsOutputToContain('COMPLETED')->assertSuccessful();

        $this->assertDatabaseCount('anvisa_catalog_imports', 1);
    }

    public function test_review_separates_pdf_evidence_from_regulatory_catalog_and_shows_conflicts(): void
    {
        $source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'collection' => 'Bulário Eletrônico', 'organization' => 'ANVISA', 'type' => 'regulatory']);
        $batch = IngestionBatch::query()->create(['name' => 'Teste', 'source_id' => $source->id, 'status' => 'COMPLETED']);
        $item = IngestionBatchItem::query()->create([
            'ingestion_batch_id' => $batch->id, 'original_filename' => 'bula.pdf', 'normalized_filename' => 'bula.pdf',
            'status' => 'PENDING', 'reason' => 'no_match_found', 'sha256' => str_repeat('a', 64),
            'metadata' => ['preliminary_identification' => [
                'fields' => ['product_name' => 'Produto do PDF', 'registration_number' => '1.0367.0004'], 'score' => 80,
                'ambiguous_fields' => [], 'regulatory_validation' => ['status' => 'CONFLICT',
                    'conflicts' => ['registration_product_mismatch'], 'catalog' => ['registration_number' => '103670004',
                        'product_name' => 'ATROVENT', 'active_ingredient' => 'BROMETO DE IPRATRÓPIO', 'registration_status' => 'Ativo'],
                    'provenance' => ['dataset' => 'ANVISA - Dados Abertos de Medicamentos', 'catalog_import_id' => 1]],
            ]],
        ]);

        Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->assertSee('Dados identificados no PDF')->assertSee('Produto do PDF')
            ->assertSee('Validação no catálogo ANVISA')->assertSee('ATROVENT')->assertSee('Divergente')
            ->assertSee('o produto do PDF não corresponde ao produto do catálogo');
    }

    /** @param list<list<string>> $rows */
    private function catalogFile(string $name, array $rows): string
    {
        $header = ['TIPO_PRODUTO', 'NOME_PRODUTO', 'DATA_FINALIZACAO_PROCESSO', 'CATEGORIA_REGULATORIA', 'NUMERO_REGISTRO_PRODUTO', 'DATA_VENCIMENTO_REGISTRO', 'NUMERO_PROCESSO', 'CLASSE_TERAPEUTICA', 'EMPRESA_DETENTORA_REGISTRO', 'SITUACAO_REGISTRO', 'PRINCIPIO_ATIVO'];
        $lines = [implode(';', $header)];
        foreach ($rows as $row) {
            $lines[] = implode(';', array_map(fn (string $value): string => '"'.$value.'"', $row));
        }
        Storage::disk('local')->put($name, mb_convert_encoding(implode("\n", $lines)."\n", 'ISO-8859-1', 'UTF-8'));

        return Storage::disk('local')->path($name);
    }
}
