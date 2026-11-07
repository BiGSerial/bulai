<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidências extraídas da bula pela IA quando os trechos recuperados não respondem à pergunta (consulta à bula inteira):
 * trecho literal, seção, página, confiança, método e situação de revisão, sempre ligadas ao documento, à sua versão
 * (hash do conteúdo) e ao trecho de origem. São reaproveitadas nas próximas perguntas sem reler a bula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_leaflet_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('knowledge_documents')->cascadeOnDelete();
            $table->foreignId('chunk_id')->nullable()->constrained('knowledge_chunks')->nullOnDelete();
            $table->foreignId('medicine_id')->nullable()->constrained('anvisa_medicines')->nullOnDelete();
            $table->string('topic', 32)->nullable();
            $table->string('section', 160)->nullable();
            $table->unsignedInteger('page_start')->nullable();
            $table->text('excerpt');
            $table->text('summary')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->string('extraction_method', 32);
            $table->string('review_status', 16)->default('pending');
            $table->string('document_sha256', 64)->nullable();
            $table->string('document_version', 64)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('run_id', 36)->nullable();
            $table->string('question', 400)->nullable();
            $table->timestamp('extracted_at');
            $table->timestamps();

            $table->index(['document_id', 'topic']);
            $table->index('medicine_id');
            $table->index('review_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_leaflet_facts');
    }
};
