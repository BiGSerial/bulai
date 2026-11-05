<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Relatórios gerados pela administração (sob demanda ou por comando), com estado, parâmetros e arquivo resultante. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 40)->index();
            $table->string('format', 10);
            $table->string('status', 20)->index();
            $table->jsonb('parameters')->nullable();
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('rows')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('error_code', 60)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_reports');
    }
};
