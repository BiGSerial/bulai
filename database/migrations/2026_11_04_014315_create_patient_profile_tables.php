<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('health_consent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('patient_allergies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('substance');
            $table->string('normalized_substance');
            $table->string('reaction')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'normalized_substance']);
        });

        Schema::create('patient_medications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained('anvisa_medicines')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'medicine_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_medications');
        Schema::dropIfExists('patient_allergies');
        Schema::dropIfExists('patient_profiles');
    }
};
