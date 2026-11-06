<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programação dinâmica por intervalo: remédios "de 8 em 8 horas" guardam o intervalo em minutos e, no modo
 * `interval`, os próximos horários são recalculados a partir do horário real em que o paciente marcou "Tomei".
 * No modo `fixed` valem os horários fixos de `dose_times` (comportamento anterior).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_medications', function (Blueprint $table): void {
            $table->unsignedSmallInteger('interval_minutes')->nullable()->after('dose_times');
            $table->string('schedule_mode', 10)->default('fixed')->after('interval_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('patient_medications', function (Blueprint $table): void {
            $table->dropColumn(['interval_minutes', 'schedule_mode']);
        });
    }
};
