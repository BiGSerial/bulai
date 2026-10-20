<?php

namespace Tests\Feature\Knowledge;

use App\Jobs\ImportAnvisaCatalog;
use App\Livewire\Admin\Knowledge\Catalog\Index;
use App\Models\Knowledge\AnvisaCatalogImport;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\User;
use App\Services\Knowledge\AnvisaMedicineCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

final class AnvisaCatalogAdminPageTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'TIPO_PRODUTO;NOME_PRODUTO;DATA_FINALIZACAO_PROCESSO;CATEGORIA_REGULATORIA;NUMERO_REGISTRO_PRODUTO;DATA_VENCIMENTO_REGISTRO;NUMERO_PROCESSO;CLASSE_TERAPEUTICA;EMPRESA_DETENTORA_REGISTRO;SITUACAO_REGISTRO;PRINCIPIO_ATIVO';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake(config('knowledge.filesystem_disk'));
    }

    private function csv(string $name = 'DADOS_ABERTOS_MEDICAMENTOS.csv'): UploadedFile
    {
        $row = '"MEDICAMENTO";"ATROVENT";"";"Novo";"103670004";"";"2599";"";"EMPRESA A";"Ativo";"BROMETO DE IPRATRÓPIO"';
        $content = mb_convert_encoding(self::HEADER."\n{$row}\n", 'ISO-8859-1', 'UTF-8');

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_valid_upload_queues_import_and_records_history(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin())->test(Index::class)
            ->set('file', $this->csv())->call('import')
            ->assertHasNoErrors()->assertSee('colocada na fila');

        $import = AnvisaCatalogImport::query()->sole();
        $this->assertSame('QUEUED', $import->status);
        $this->assertNotNull($import->uploaded_by);
        Storage::disk(config('knowledge.filesystem_disk'))->assertExists($import->stored_path);
        Queue::assertPushed(ImportAnvisaCatalog::class, fn (ImportAnvisaCatalog $job): bool => $job->catalogImportId === $import->id);
    }

    public function test_job_imports_file_updates_history_and_removes_upload(): void
    {
        Queue::fake();
        Livewire::actingAs($this->admin())->test(Index::class)->set('file', $this->csv())->call('import');
        $import = AnvisaCatalogImport::query()->sole();
        $path = $import->stored_path;

        (new ImportAnvisaCatalog($import->id))->handle(app(AnvisaMedicineCsvImporter::class));

        $import->refresh();
        $this->assertSame('COMPLETED', $import->status);
        $this->assertSame(1, $import->rows_inserted);
        $this->assertNull($import->stored_path);
        $this->assertDatabaseCount('anvisa_catalog_imports', 1);
        $this->assertSame('BROMETO DE IPRATRÓPIO', AnvisaMedicine::query()->sole()->active_ingredient);
        Storage::disk(config('knowledge.filesystem_disk'))->assertMissing($path);

        Livewire::actingAs($this->admin())->test(Index::class)
            ->assertSee('DADOS_ABERTOS_MEDICAMENTOS.csv')->assertSee('Concluída')->assertDontSee('O catálogo está vazio');
    }

    public function test_invalid_extension_and_oversized_files_are_rejected(): void
    {
        Queue::fake();
        config(['knowledge.anvisa_catalog.max_kilobytes' => 1]);

        Livewire::actingAs($this->admin())->test(Index::class)
            ->set('file', UploadedFile::fake()->create('catalogo.pdf', 10, 'application/pdf'))->call('import')
            ->assertHasErrors('file');
        Livewire::actingAs($this->admin())->test(Index::class)
            ->set('file', UploadedFile::fake()->create('grande.csv', 10, 'text/csv'))->call('import')
            ->assertHasErrors('file');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('anvisa_catalog_imports', 0);
    }

    public function test_second_simultaneous_import_is_refused(): void
    {
        Queue::fake();
        AnvisaCatalogImport::factory()->create(['status' => 'PROCESSING']);

        Livewire::actingAs($this->admin())->test(Index::class)
            ->set('file', $this->csv())->call('import')
            ->assertSee('Já existe uma importação em andamento')->assertSee('Importação em andamento');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('anvisa_catalog_imports', 1);
    }

    public function test_empty_catalog_shows_warning(): void
    {
        Livewire::actingAs($this->admin())->test(Index::class)
            ->assertOk()->assertSee('Catálogo ANVISA')->assertSee('validação regulatória fica indisponível');
    }

    public function test_job_failure_is_recorded_without_leaking_internals(): void
    {
        Queue::fake();
        $import = AnvisaCatalogImport::factory()->create(['status' => 'PROCESSING', 'stored_path' => 'anvisa-catalog/segredo.csv']);

        (new ImportAnvisaCatalog($import->id))->failed(new \RuntimeException('SQLSTATE /var/www/segredo'));

        $import->refresh();
        $this->assertSame('FAILED', $import->status);
        $this->assertNotNull($import->error_reference);
        Livewire::actingAs($this->admin())->test(Index::class)
            ->assertSee('A última importação falhou')->assertSee($import->error_reference)
            ->assertDontSee('SQLSTATE')->assertDontSee('segredo');
    }

    public function test_page_is_restricted_to_admins(): void
    {
        $this->get(route('admin.knowledge.catalog.index'))->assertRedirect();
        $this->actingAs(User::factory()->create())->get(route('admin.knowledge.catalog.index'))->assertForbidden();
    }
}
