<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consumo de IA por usuário e com o detalhe que cada provedor devolve.
 *
 * input_tokens passa a ser o total de entrada processada (inclusive a parte lida ou gravada em cache); o detalhe
 * fica em cache_read_tokens/cache_write_tokens. provider_usage guarda o objeto `usage` da resposta da API sem
 * alteração, porque cada provedor descreve o consumo de um jeito (Anthropic, OpenAI...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('origin', 120)->nullable()->after('capability');
            $table->string('response_model', 120)->nullable()->after('model');
            $table->unsignedInteger('cache_read_tokens')->nullable()->after('output_tokens');
            $table->unsignedInteger('cache_write_tokens')->nullable()->after('cache_read_tokens');
            $table->unsignedInteger('reasoning_tokens')->nullable()->after('cache_write_tokens');
            $table->jsonb('provider_usage')->nullable()->after('reasoning_tokens');

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['origin', 'response_model', 'cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens', 'provider_usage']);
        });
    }
};
