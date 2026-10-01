<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingestion_batches', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name');
            $table->unsignedInteger('total_items')->default(0)->after('status');
            $table->unsignedInteger('associated_items')->default(0)->after('total_items');
            $table->unsignedInteger('duplicate_items')->default(0)->after('associated_items');
            $table->unsignedInteger('pending_items')->default(0)->after('duplicate_items');
            $table->unsignedInteger('error_items')->default(0)->after('pending_items');
        });

        Schema::table('knowledge_documents', function (Blueprint $table): void {
            $table->string('source_document_id')->nullable()->after('external_key');
            $table->string('normalized_filename')->nullable()->after('original_filename');

            $table->index('source_document_id');
            $table->index('normalized_filename');
        });

        Schema::create('ingestion_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingestion_batch_id')->constrained('ingestion_batches')->cascadeOnDelete();
            $table->foreignId('knowledge_document_id')->nullable()->constrained('knowledge_documents')->nullOnDelete();
            $table->string('external_key')->nullable();
            $table->string('source_document_id')->nullable();
            $table->string('original_filename');
            $table->string('normalized_filename')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('storage_path')->nullable();
            $table->string('status')->default('PENDING');
            $table->string('resolution_method')->nullable();
            $table->string('reason')->nullable();
            $this->jsonColumn($table, 'metadata');
            $table->timestamps();

            $table->index(['ingestion_batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_batch_items');

        Schema::table('knowledge_documents', function (Blueprint $table): void {
            $table->dropIndex(['source_document_id']);
            $table->dropIndex(['normalized_filename']);
            $table->dropColumn(['source_document_id', 'normalized_filename']);
        });

        Schema::table('ingestion_batches', function (Blueprint $table): void {
            $table->dropColumn(['description', 'total_items', 'associated_items', 'duplicate_items', 'pending_items', 'error_items']);
        });
    }

    private function jsonColumn(Blueprint $table, string $name): mixed
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? $table->jsonb($name)->default(DB::raw("'{}'::jsonb"))
            : $table->json($name)->default('{}');
    }
};
