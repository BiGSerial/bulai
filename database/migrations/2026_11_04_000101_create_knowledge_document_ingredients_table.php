<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_document_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('knowledge_documents')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('active_ingredients')->cascadeOnDelete();
            $table->string('method', 20);
            $table->decimal('confidence', 3, 2);
            $table->timestamps();

            $table->unique(['document_id', 'ingredient_id']);
            $table->index('ingredient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_document_ingredients');
    }
};
