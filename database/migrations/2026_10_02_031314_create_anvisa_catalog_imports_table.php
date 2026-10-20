<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('anvisa_medicines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained('knowledge_sources')->nullOnDelete();
            $table->foreignId('catalog_import_id')->constrained('anvisa_catalog_imports')->restrictOnDelete();
            $table->string('record_key')->unique();
            $table->string('registration_number_original')->nullable();
            $table->string('registration_number')->nullable()->index();
            $table->string('process_number')->nullable()->index();
            $table->string('product_name');
            $table->string('normalized_product_name');
            $table->string('regulatory_category')->nullable();
            $table->text('active_ingredient')->nullable();
            $table->text('normalized_active_ingredient')->nullable();
            $table->string('therapeutic_class')->nullable();
            $table->string('registration_holder');
            $table->string('normalized_registration_holder');
            $table->string('registration_status');
            $table->string('process_completed_on')->nullable();
            $table->string('registration_expires_on')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->index(['normalized_product_name', 'registration_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anvisa_medicines');
    }
};
