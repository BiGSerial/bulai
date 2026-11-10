<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Trechos de uma bula estrangeira por seção (código LOINC), com o original e a tradução para o português. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_label_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_label_id')->constrained('external_labels')->cascadeOnDelete();
            $table->string('section_code', 20);
            $table->string('section_name', 120);
            $table->unsignedSmallInteger('position');
            $table->text('text_original');
            $table->text('text_translated')->nullable();
            $table->string('translation_status', 20);
            $table->string('translation_model', 120)->nullable();
            $table->timestamp('translated_at')->nullable();
            $table->timestamps();

            $table->unique(['external_label_id', 'section_code', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_label_sections');
    }
};
