<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aparelhos do aplicativo Android (mobile/android) ligados à conta do paciente. Cada instalação recebe um token próprio
 * (só o sha256 fica aqui) que a parte nativa usa para buscar a agenda das doses e marcar "Tomei"/"Pulei" pelos alarmes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('installation_id');
            $table->string('token_hash', 64)->unique();
            $table->string('platform', 20)->default('android');
            $table->string('device_name', 120)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'installation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_devices');
    }
};
