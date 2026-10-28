<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drug_interaction_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('dataset_name');
            $table->string('original_filename');
            $table->char('sha256', 64)->unique();
            $table->string('status', 20)->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stored_path')->nullable();
            $table->string('error_reference', 36)->nullable();
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('rows_inserted')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->unsignedInteger('rows_rejected')->default(0);
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drug_interaction_imports');
    }
};
