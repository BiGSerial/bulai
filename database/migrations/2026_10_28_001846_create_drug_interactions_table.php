<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drug_interactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('drug_interaction_imports')->restrictOnDelete();
            $table->string('source', 30)->default('ddinter');
            $table->string('pair_key')->unique();
            $table->string('drug_a_external_id', 30);
            $table->string('drug_a_name');
            $table->string('normalized_drug_a_name')->index();
            $table->string('drug_b_external_id', 30);
            $table->string('drug_b_name');
            $table->string('normalized_drug_b_name')->index();
            $table->string('severity', 10)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drug_interactions');
    }
};
