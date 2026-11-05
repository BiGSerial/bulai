<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programação do tratamento e cadastro de remédios fora do catálogo.
 *
 * - `patient_medications` passa a aceitar remédio sem correspondência no catálogo ANVISA (`medicine_id` nulo,
 *   `name_as_written` preenchido, `catalog_status = unmatched`), com horários de dose e lembretes.
 * - `unmatched_medicine_reports`: fila dos administradores para resolver remédios que os pacientes cadastraram e o
 *   catálogo não reconheceu. Ao resolver, o remédio do paciente é ligado ao catálogo.
 * - `medication_dose_events`: o que o paciente marcou como "tomei" ou "pulei" em cada horário programado.
 * - `patient_profiles`: fuso horário e preferências de lembrete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_medications', function (Blueprint $table): void {
            $table->unsignedBigInteger('medicine_id')->nullable()->change();
            $table->string('name_as_written', 120)->nullable()->after('medicine_id');
            $table->string('catalog_status', 20)->default('matched')->after('name_as_written')->index();
            $table->jsonb('dose_times')->nullable()->after('frequency');
            $table->boolean('as_needed')->default(false)->after('dose_times');
            $table->boolean('reminders_enabled')->default(true)->after('as_needed');
        });

        Schema::create('unmatched_medicine_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_medication_id')->constrained('patient_medications')->cascadeOnDelete();
            $table->string('name_as_written', 120);
            $table->string('normalized_name', 120)->index();
            $table->string('dosage', 120)->nullable();
            $table->string('frequency', 120)->nullable();
            $table->string('status', 20)->index();
            $table->foreignId('resolved_medicine_id')->nullable()->constrained('anvisa_medicines')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_note', 255)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('medication_dose_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_medication_id')->constrained('patient_medications')->cascadeOnDelete();
            $table->timestamp('scheduled_for');
            $table->string('status', 20);
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['patient_medication_id', 'scheduled_for']);
            $table->index(['user_id', 'scheduled_for']);
        });

        Schema::table('patient_profiles', function (Blueprint $table): void {
            $table->string('timezone', 60)->default('America/Sao_Paulo')->after('health_consent_at');
            $table->boolean('reminders_enabled')->default(true)->after('timezone');
            $table->boolean('reminders_email')->default(false)->after('reminders_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('patient_profiles', function (Blueprint $table): void {
            $table->dropColumn(['timezone', 'reminders_enabled', 'reminders_email']);
        });
        Schema::dropIfExists('medication_dose_events');
        Schema::dropIfExists('unmatched_medicine_reports');
        Schema::table('patient_medications', function (Blueprint $table): void {
            $table->dropColumn(['name_as_written', 'catalog_status', 'dose_times', 'as_needed', 'reminders_enabled']);
        });
    }
};
