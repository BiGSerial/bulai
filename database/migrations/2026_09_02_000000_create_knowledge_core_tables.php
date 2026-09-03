<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type')->default('document');
            $table->string('organization')->nullable();
            $table->string('url')->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('priority')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['name', 'organization']);
        });

        Schema::create('ingestion_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('source_id')->nullable()->constrained('knowledge_sources')->nullOnDelete();
            $table->string('status')->default('PENDING');
            $this->jsonColumn($table, 'metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('knowledge_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingestion_batch_id')->nullable()->constrained('ingestion_batches')->nullOnDelete();
            $table->foreignId('source_id')->constrained('knowledge_sources')->restrictOnDelete();
            $table->string('external_key')->unique();
            $table->string('title');
            $table->string('document_type');
            $table->string('language', 16)->default('pt-BR');
            $table->string('original_filename')->nullable();
            $table->string('storage_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('sha256', 64)->nullable()->unique();
            $this->jsonColumn($table, 'metadata');
            $table->string('content_status')->default('PENDING');
            $table->string('file_status')->default('PENDING');
            $table->string('embedding_status')->default('PENDING');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['content_status', 'file_status', 'embedding_status']);
            $table->index(['document_type', 'language']);
        });

        Schema::create('knowledge_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('knowledge_documents')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->string('section')->nullable();
            $table->unsignedInteger('page_start')->nullable();
            $table->unsignedInteger('page_end')->nullable();
            $table->longText('content');
            $table->unsignedInteger('token_count')->nullable();
            $this->jsonColumn($table, 'metadata');
            $table->timestamps();

            $table->unique(['document_id', 'chunk_index']);
            $table->index(['document_id', 'section']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
            DB::statement('ALTER TABLE knowledge_chunks ADD COLUMN embedding vector');
        } else {
            Schema::table('knowledge_chunks', function (Blueprint $table): void {
                $table->text('embedding')->nullable();
            });
        }

        Schema::create('ingestion_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->string('scope')->default('knowledge:ingest');
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['scope', 'expires_at', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_tokens');
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_documents');
        Schema::dropIfExists('ingestion_batches');
        Schema::dropIfExists('knowledge_sources');
    }

    private function jsonColumn(Blueprint $table, string $name): mixed
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? $table->jsonb($name)->default(DB::raw("'{}'::jsonb"))
            : $table->json($name)->default('{}');
    }
};
