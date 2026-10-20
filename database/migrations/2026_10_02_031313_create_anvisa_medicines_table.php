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
        Schema::create('anvisa_catalog_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained('knowledge_sources')->nullOnDelete();
            $table->string('dataset_name');
            $table->string('original_filename');
            $table->string('sha256', 64)->unique();
            $table->string('encoding', 32);
            $table->string('delimiter', 4)->default(';');
            $table->string('status');
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('rows_inserted')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->unsignedInteger('rows_rejected')->default(0);
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['dataset_name', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anvisa_catalog_imports');
    }
};
