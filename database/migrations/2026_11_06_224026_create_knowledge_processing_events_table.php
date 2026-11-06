<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Linha do tempo do processamento de cada bula: uma linha por etapa (texto nativo, OCR, normalização,
     * trechos, embeddings, vínculo com o princípio ativo), com estado, duração e detalhes para a tela e o SSE.
     */
    public function up(): void
    {
        Schema::create('knowledge_processing_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('knowledge_documents')->cascadeOnDelete();
            $table->string('step', 32);
            $table->string('state', 16);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('detail')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'started_at']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_processing_events');
    }
};
