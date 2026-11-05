<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receitas médicas e posologia do paciente, registro auditável de cada pergunta respondida pela IA e cache da
 * "bula humanizada" por conjunto de princípios ativos. Tudo o que a IA responde ao usuário fica rastreável no backend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_prescriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('prescriber_name', 120);
            $table->string('prescriber_registration', 40)->nullable();
            $table->date('issued_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('patient_medications', function (Blueprint $table): void {
            $table->foreignId('prescription_id')->nullable()->after('medicine_id')->constrained('patient_prescriptions')->nullOnDelete();
            $table->string('dosage', 120)->nullable()->after('prescription_id');
            $table->string('frequency', 120)->nullable()->after('dosage');
            $table->date('started_at')->nullable()->after('frequency');
            $table->date('ends_at')->nullable()->after('started_at');
            $table->string('notes', 255)->nullable()->after('ends_at');
        });

        Schema::create('medication_questions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->nullable()->constrained('anvisa_medicines')->nullOnDelete();
            $table->string('medicine_name');
            $table->text('question');
            $table->string('status', 30)->index();
            $table->text('answer')->nullable();
            $table->jsonb('evidences')->nullable();
            $table->jsonb('treatment_context')->nullable();
            $table->jsonb('generation')->nullable();
            $table->jsonb('retrieval')->nullable();
            $table->jsonb('pipeline')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('medicine_leaflet_summaries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Conjunto ordenado de ids de princípios ativos ("12-345"): a bula humanizada vale para todos os produtos equivalentes.
            $table->string('ingredient_key', 120);
            $table->string('prompt_version', 60);
            // Hash dos content_hash dos documentos usados: muda quando uma bula é reprocessada ou acrescentada.
            $table->string('documents_fingerprint', 64);
            $table->string('status', 30)->index();
            $table->jsonb('sections')->nullable();
            $table->jsonb('evidences')->nullable();
            $table->jsonb('generation')->nullable();
            $table->jsonb('retrieval')->nullable();
            $table->jsonb('pipeline')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();

            $table->unique(['ingredient_key', 'prompt_version', 'documents_fingerprint'], 'leaflet_summaries_cache_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_leaflet_summaries');
        Schema::dropIfExists('medication_questions');
        Schema::table('patient_medications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('prescription_id');
            $table->dropColumn(['dosage', 'frequency', 'started_at', 'ends_at', 'notes']);
        });
        Schema::dropIfExists('patient_prescriptions');
    }
};
