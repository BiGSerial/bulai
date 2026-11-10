<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulas de fontes estrangeiras (FDA, via openFDA/DailyMed), uma por princípio ativo, usadas só como
 * complemento quando as bulas brasileiras não trazem a informação. Guarda a origem completa para citação e auditoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_labels', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 30);
            $table->foreignId('ingredient_id')->constrained('active_ingredients')->cascadeOnDelete();
            $table->string('status', 20);
            // Por onde a bula veio: openfda (principal) ou dailymed (reserva, quando o openFDA falha ou não tem bula aceitável).
            $table->string('retrieved_via', 20)->nullable();
            $table->string('searched_name')->nullable();
            $table->uuid('set_id')->nullable();
            $table->unsignedInteger('spl_version')->nullable();
            $table->string('title', 500)->nullable();
            $table->string('labeler')->nullable();
            $table->date('published_at')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->string('unii', 20)->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'ingredient_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_labels');
    }
};
