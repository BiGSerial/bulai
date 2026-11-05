<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_chunks', function (Blueprint $table): void {
            $table->string('embedding_model')->nullable()->index();
            $table->unsignedSmallInteger('embedding_dimensions')->nullable();
            $table->string('embedding_content_hash', 64)->nullable();
            $table->timestamp('embedded_at')->nullable();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE knowledge_chunks ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('portuguese', coalesce(section, '') || ' ' || content)) STORED");
            DB::statement('CREATE INDEX knowledge_chunks_search_vector_idx ON knowledge_chunks USING GIN (search_vector)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS knowledge_chunks_search_vector_idx');
            DB::statement('ALTER TABLE knowledge_chunks DROP COLUMN IF EXISTS search_vector');
        }

        Schema::table('knowledge_chunks', function (Blueprint $table): void {
            $table->dropColumn(['embedding_model', 'embedding_dimensions', 'embedding_content_hash', 'embedded_at']);
        });
    }
};
