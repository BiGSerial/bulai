<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Todo remédio tem prazo: uma data de término ou "Uso contínuo" marcado. Os que já estavam sem término continuam valendo
 * como uso contínuo (era o que a falta de data significava até aqui).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_medications', function (Blueprint $table) {
            $table->boolean('continuous_use')->default(false)->after('ends_at');
        });
        DB::table('patient_medications')->whereNull('ends_at')->update(['continuous_use' => true]);
    }

    public function down(): void
    {
        Schema::table('patient_medications', function (Blueprint $table) {
            $table->dropColumn('continuous_use');
        });
    }
};
