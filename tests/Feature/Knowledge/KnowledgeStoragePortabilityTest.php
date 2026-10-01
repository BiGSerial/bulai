<?php

namespace Tests\Feature\Knowledge;

use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Services\Knowledge\KnowledgeFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class KnowledgeStoragePortabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_disk_persists_logical_identity_and_stream_hash(): void
    {
        Storage::fake('portable-knowledge');
        config(['knowledge.filesystem_disk' => 'portable-knowledge']);
        $document = $this->document();
        $file = $this->pdf('dose.pdf', '500 mg 20 mg/mL 5%');

        $attached = app(KnowledgeFileService::class)->attachPdf($document, $file);

        $this->assertSame('portable-knowledge', $attached->storage_disk);
        $this->assertSame($attached->storage_path, $attached->storage_key);
        $this->assertStringStartsWith("knowledge/originals/{$document->id}/", $attached->storage_key);
        $this->assertFalse(str_starts_with($attached->storage_key, '/'));
        Storage::disk('portable-knowledge')->assertExists($attached->storage_key);
        $this->assertSame(hash('sha256', "%PDF-1.4\n500 mg 20 mg/mL 5%\n%%EOF"), app(KnowledgeFileService::class)->hashObject('portable-knowledge', $attached->storage_key));
    }

    public function test_local_materialization_is_always_cleaned_after_success_and_parser_failure(): void
    {
        Storage::fake('portable-knowledge');
        config(['knowledge.filesystem_disk' => 'portable-knowledge']);
        $key = 'knowledge/originals/1/object.pdf';
        Storage::disk('portable-knowledge')->put($key, '%PDF portable');
        $service = app(KnowledgeFileService::class);
        $successfulPath = null;

        $result = $service->withLocalCopy('portable-knowledge', $key, function (string $path) use (&$successfulPath): string {
            $successfulPath = $path;
            $this->assertFileExists($path);

            return file_get_contents($path);
        });

        $this->assertSame('%PDF portable', $result);
        $this->assertFileDoesNotExist($successfulPath);

        $failedPath = null;
        try {
            $service->withLocalCopy('portable-knowledge', $key, function (string $path) use (&$failedPath): never {
                $failedPath = $path;
                throw new RuntimeException('parser failed');
            });
            $this->fail('Expected parser failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('parser failed', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($failedPath);
        Storage::disk('portable-knowledge')->assertExists($key);
    }

    public function test_pending_object_can_be_promoted_between_configured_adapters_without_rename(): void
    {
        Storage::fake('legacy-private');
        Storage::fake('portable-knowledge');
        config(['knowledge.filesystem_disk' => 'portable-knowledge']);
        $document = $this->document();
        $pendingKey = 'knowledge/pending/99/source.pdf';
        $contents = "%PDF-1.4\nportable promotion\n%%EOF";
        Storage::disk('legacy-private')->put($pendingKey, $contents);

        $definitiveKey = app(KnowledgeFileService::class)->promotePendingObject($document, 'legacy-private', $pendingKey);

        Storage::disk('legacy-private')->assertExists($pendingKey);
        Storage::disk('portable-knowledge')->assertExists($definitiveKey);
        Storage::disk('portable-knowledge')->assertDirectoryEmpty('knowledge/staging');
        $this->assertSame($contents, Storage::disk('portable-knowledge')->get($definitiveKey));
    }

    private function document(): KnowledgeDocument
    {
        $source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'active' => true]);

        return KnowledgeDocument::query()->create([
            'source_id' => $source->id,
            'external_key' => 'portable-doc',
            'title' => 'Documento portátil',
            'document_type' => 'bula',
            'metadata' => [],
            'content_status' => KnowledgeDocument::STATUS_PENDING,
            'file_status' => KnowledgeDocument::STATUS_PENDING,
            'embedding_status' => KnowledgeDocument::STATUS_PENDING,
        ]);
    }

    private function pdf(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n{$content}\n%%EOF");
    }
}
