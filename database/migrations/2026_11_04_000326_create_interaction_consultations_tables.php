<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interaction_consultations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->index();
            $table->jsonb('medicines');
            $table->jsonb('pipeline')->nullable();
            $table->string('run_id', 36)->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('interaction_pair_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consultation_id')->constrained('interaction_consultations')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('medicine_a_id')->nullable()->constrained('anvisa_medicines')->nullOnDelete();
            $table->foreignId('medicine_b_id')->nullable()->constrained('anvisa_medicines')->nullOnDelete();
            $table->string('label_a');
            $table->string('label_b');
            $table->jsonb('ingredients_a')->nullable();
            $table->jsonb('ingredients_b')->nullable();
            $table->string('verdict', 40);
            $table->string('severity', 10)->nullable();
            $table->string('severity_source', 30)->nullable();
            $table->jsonb('structured')->nullable();
            $table->text('explanation')->nullable();
            $table->jsonb('evidences')->nullable();
            $table->jsonb('limitations')->nullable();
            $table->jsonb('generation')->nullable();
            $table->jsonb('retrieval')->nullable();
            $table->jsonb('timings')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamps();

            $table->unique(['consultation_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interaction_pair_results');
        Schema::dropIfExists('interaction_consultations');
    }
};
