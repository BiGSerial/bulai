<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perguntas que nem a bula inteira respondeu (ou sem bula/princípio ativo) entram na fila de revisão humana da
 * administração, para adquirir fontes confiáveis: `review_status` (pending | resolved | dismissed) e `review_reason`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_questions', function (Blueprint $table): void {
            $table->string('review_status', 16)->nullable()->after('guard');
            $table->string('review_reason', 32)->nullable()->after('review_status');
            $table->foreignId('reviewed_by')->nullable()->after('review_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->index(['review_status', 'medicine_id']);
        });
    }

    public function down(): void
    {
        Schema::table('medication_questions', function (Blueprint $table): void {
            $table->dropIndex(['review_status', 'medicine_id']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['review_status', 'review_reason', 'reviewed_at']);
        });
    }
};
