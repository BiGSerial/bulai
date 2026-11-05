<?php

namespace Tests\Feature\Knowledge;

use App\Jobs\ImportDrugInteractions;
use App\Livewire\Admin\Knowledge\Interactions\Index;
use App\Models\Knowledge\DrugInteraction;
use App\Models\Knowledge\DrugInteractionImport;
use App\Models\User;
use App\Services\Knowledge\DrugInteractionCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

final class DrugInteractionImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = "DDInterID_A,Drug_A,DDInterID_B,Drug_B,Level\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake(config('knowledge.filesystem_disk'));
    }

    private function write(string $body, string $name = 'ddinter_downloads_code_A.csv'): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('ddi_').$name;
        file_put_contents($path, $body);

        return $path;
    }

    public function test_import_stores_each_pair_once_in_canonical_order_and_is_idempotent(): void
    {
        $path = $this->write(self::HEADER."DDInter1263,Naltrexone,DDInter1,Abacavir,Moderate\nDDInter1,Abacavir,DDInter1348,Orlistat,Minor\nDDInter1,Abacavir,DDInter1263,Naltrexone,Moderate\n");

        $import = app(DrugInteractionCsvImporter::class)->import($path);

        $this->assertSame('COMPLETED', $import->status);
        $this->assertSame([3, 2, 0, 1, 0], [$import->rows_read, $import->rows_inserted, $import->rows_updated, $import->rows_skipped, $import->rows_rejected]);
        $pair = DrugInteraction::query()->where('pair_key', 'ddinter:ddinter1:ddinter1263')->firstOrFail();
        $this->assertSame(['Abacavir', 'Naltrexone', 'moderate'], [$pair->drug_a_name, $pair->drug_b_name, $pair->severity]);

        $this->assertSame($import->id, app(DrugInteractionCsvImporter::class)->import($path)->id);
        $this->assertSame(2, DrugInteraction::query()->count());
    }

    public function test_same_pair_in_another_file_updates_instead_of_duplicating(): void
    {
        $importer = app(DrugInteractionCsvImporter::class);
        $importer->import($this->write(self::HEADER."DDInter1,Abacavir,DDInter5,Aspirin,Minor\n"));
        $second = $importer->import($this->write(self::HEADER."DDInter5,Aspirin,DDInter1,Abacavir,Major\n", 'ddinter_downloads_code_B.csv'));

        $this->assertSame([0, 1], [$second->rows_inserted, $second->rows_updated]);
        $this->assertSame(1, DrugInteraction::query()->count());
        $this->assertSame('major', DrugInteraction::query()->firstOrFail()->severity);
    }

    public function test_invalid_rows_are_rejected_and_names_are_normalized(): void
    {
        $path = $this->write("\xEF\xBB\xBF".self::HEADER."DDInter2,Ácido  Acetilsalicílico,DDInter3,Warfarin,Major\nXX,Bad,DDInter3,Warfarin,Major\nDDInter2,,DDInter3,Warfarin,Major\nDDInter2,A,DDInter2,A,Major\nDDInter2,A,DDInter4,B,Severe\n");

        $import = app(DrugInteractionCsvImporter::class)->import($path);

        $this->assertSame([5, 1, 4], [$import->rows_read, $import->rows_inserted, $import->rows_rejected]);
        $this->assertSame('acido acetilsalicilico', DrugInteraction::query()->firstOrFail()->normalized_drug_a_name);
        $this->assertSame(1, DrugInteraction::query()->involving('warfarin')->count());
    }

    public function test_wrong_header_fails_the_import_without_writing_rows(): void
    {
        try {
            app(DrugInteractionCsvImporter::class)->import($this->write("a,b,c\n1,2,3\n"));
            $this->fail('Esperava falha de cabeçalho.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('INTERACTIONS_HEADERS_INVALID', $exception->getMessage());
        }

        $this->assertSame('FAILED', DrugInteractionImport::query()->firstOrFail()->status);
        $this->assertSame(0, DrugInteraction::query()->count());
    }

    public function test_upload_queues_one_job_per_file_and_skips_files_already_imported(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $a = UploadedFile::fake()->createWithContent('ddinter_downloads_code_A.csv', self::HEADER."DDInter1,A,DDInter2,B,Major\n");
        $b = UploadedFile::fake()->createWithContent('ddinter_downloads_code_B.csv', self::HEADER."DDInter3,C,DDInter4,D,Minor\n");

        Livewire::actingAs($admin)->test(Index::class)->set('files', [$a, $b])->call('import')
            ->assertHasNoErrors()->assertSee('2 arquivos recebidos');
        Queue::assertPushed(ImportDrugInteractions::class, 2);
        $this->assertSame(2, DrugInteractionImport::query()->where('status', 'QUEUED')->count());

        DrugInteractionImport::query()->update(['status' => 'COMPLETED']);
        Livewire::actingAs($admin)->test(Index::class)->set('files', [UploadedFile::fake()->createWithContent('x.csv', self::HEADER."DDInter1,A,DDInter2,B,Major\n")])
            ->call('import')->assertSee('já foram importados');
        Queue::assertPushed(ImportDrugInteractions::class, 2);
    }

    public function test_job_imports_the_stored_file_and_discards_it(): void
    {
        $disk = Storage::disk(config('knowledge.filesystem_disk'));
        $disk->put('drug-interactions/f.csv', self::HEADER."DDInter1,A,DDInter2,B,Major\n");
        $import = DrugInteractionImport::factory()->create(['status' => 'QUEUED', 'stored_path' => 'drug-interactions/f.csv', 'rows_read' => 0, 'rows_inserted' => 0]);

        (new ImportDrugInteractions($import->id))->handle(app(DrugInteractionCsvImporter::class));

        $this->assertSame('COMPLETED', $import->refresh()->status);
        $this->assertNull($import->stored_path);
        $this->assertFalse($disk->exists('drug-interactions/f.csv'));
        $this->assertSame(1, DrugInteraction::query()->count());
    }

    public function test_page_is_admin_only_and_shows_totals(): void
    {
        DrugInteraction::factory()->create(['severity' => 'major']);

        $this->get('/admin/knowledge/interactions')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/knowledge/interactions')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/knowledge/interactions')
            ->assertOk()->assertSee('Interações medicamentosas')->assertSee('Pares de fármacos')->assertSee('Interações · BULAI', false);
    }

    public function test_command_imports_a_directory(): void
    {
        $dir = sys_get_temp_dir().'/'.uniqid('ddidir_');
        mkdir($dir);
        file_put_contents("{$dir}/ddinter_downloads_code_A.csv", self::HEADER."DDInter1,A,DDInter2,B,Major\n");
        file_put_contents("{$dir}/ddinter_downloads_code_B.csv", self::HEADER."DDInter2,B,DDInter3,C,Minor\n");

        $this->artisan('knowledge:import-drug-interactions', ['path' => [$dir]])->assertSuccessful();

        $this->assertSame(2, DrugInteraction::query()->count());
    }
}
