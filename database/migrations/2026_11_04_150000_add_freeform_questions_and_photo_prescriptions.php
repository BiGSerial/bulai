<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pergunta livre sem seleção prévia (vários medicamentos detectados por pergunta, triagem de segurança registrada) e
 * receita por foto (origem, estado da extração e o resultado estruturado; a imagem nunca é guardada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_questions', function (Blueprint $table): void {
            $table->jsonb('medicines')->nullable()->after('medicine_name');
            $table->jsonb('guard')->nullable()->after('treatment_context');
        });

        Schema::table('patient_prescriptions', function (Blueprint $table): void {
            $table->string('source', 20)->default('manual')->after('user_id');
            $table->string('status', 20)->default('CONFIRMED')->after('source')->index();
            $table->jsonb('extraction')->nullable()->after('notes');
            $table->string('error_code', 60)->nullable()->after('extraction');
            $table->timestamp('confirmed_at')->nullable()->after('error_code');
        });
    }

    public function down(): void
    {
        Schema::table('patient_prescriptions', function (Blueprint $table): void {
            $table->dropColumn(['source', 'status', 'extraction', 'error_code', 'confirmed_at']);
        });
        Schema::table('medication_questions', function (Blueprint $table): void {
            $table->dropColumn(['medicines', 'guard']);
        });
    }
};
