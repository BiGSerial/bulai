<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->string('model', 120);
            $table->string('run_id', 64)->nullable()->index();
            $table->string('capability', 40);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms');
            $table->string('status', 10);
            $table->string('error_code', 40)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('provider_request_id', 100)->nullable();
            $table->decimal('estimated_cost_usd', 14, 6)->nullable();
            $table->string('pricing_reference', 40)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
