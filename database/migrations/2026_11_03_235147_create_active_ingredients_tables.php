<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('active_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->string('canonical_name');
            $table->string('normalized_name')->unique();
            $table->timestamps();
        });

        Schema::create('active_ingredient_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->constrained('active_ingredients')->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized_alias')->index();
            $table->string('language', 8);
            $table->string('origin', 20);
            $table->string('review_status', 20)->default('pending_review');
            $table->timestamps();

            $table->unique(['ingredient_id', 'normalized_alias']);
        });

        Schema::create('anvisa_medicine_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('medicine_id')->constrained('anvisa_medicines')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('active_ingredients')->cascadeOnDelete();
            $table->string('component_text');
            $table->string('method', 20);
            $table->decimal('confidence', 3, 2);
            $table->timestamps();

            $table->unique(['medicine_id', 'ingredient_id']);
            $table->index('ingredient_id');
        });

        Schema::table('drug_interactions', function (Blueprint $table): void {
            $table->foreignId('drug_a_ingredient_id')->nullable()->constrained('active_ingredients')->nullOnDelete();
            $table->foreignId('drug_b_ingredient_id')->nullable()->constrained('active_ingredients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('drug_interactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('drug_a_ingredient_id');
            $table->dropConstrainedForeignId('drug_b_ingredient_id');
        });
        Schema::dropIfExists('anvisa_medicine_ingredients');
        Schema::dropIfExists('active_ingredient_aliases');
        Schema::dropIfExists('active_ingredients');
    }
};
