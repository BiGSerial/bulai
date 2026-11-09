<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha de auditoria do modo cuidador (LGPD): convite, aceite, aprovação, permissões, revogação e cada ação de quem
 * cuida sobre os dados do paciente. Só recebe inclusões; o paciente vê o histórico de cada vínculo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caregiver_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('caregiver_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->jsonb('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['caregiver_link_id', 'created_at']);
            $table->index(['patient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caregiver_audit_events');
    }
};
