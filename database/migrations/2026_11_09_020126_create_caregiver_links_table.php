<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modo cuidador: vínculo entre o paciente e quem cuida dele, nascido de um convite com código de uso único (48 h).
 * O vínculo só dá acesso depois que o PACIENTE aprova (`ACTIVE`) e com as permissões que ele marcou.
 * O código fica guardado só como hash (para conferir e para dizer "já usado"); o texto, cifrado, até ser usado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caregiver_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('caregiver_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('status', 16)->index();
            $table->string('invite_code_hash', 64)->nullable()->unique();
            $table->text('invite_code')->nullable();
            $table->timestamp('invite_expires_at')->nullable();
            $table->boolean('can_receive_alerts')->default(true);
            $table->boolean('can_view_medications')->default(true);
            $table->boolean('can_manage_medications')->default(true);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'status']);
            $table->index(['caregiver_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caregiver_links');
    }
};
