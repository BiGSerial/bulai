<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Aprovação do cadastro: quem se cadastra pelo site nasce "pending" e só entra depois que um administrador aprova.
     * O padrão da coluna é "approved", então os usuários já existentes (backfill explícito abaixo), os criados pelo
     * administrador e os seeders continuam entrando como antes. `approval_reviewed_by/at` guardam quem decidiu e quando;
     * `terms_accepted_at` guarda o aceite do aviso de privacidade no cadastro pelo site.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('approval_status', 20)->default('approved')->after('must_change_password')->index();
            $table->foreignId('approval_reviewed_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            $table->timestamp('approval_reviewed_at')->nullable()->after('approval_reviewed_by');
            $table->timestamp('terms_accepted_at')->nullable()->after('approval_reviewed_at');
        });

        DB::table('users')->update(['approval_status' => 'approved']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approval_reviewed_by');
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['approval_status', 'approval_reviewed_at', 'terms_accepted_at']);
        });
    }
};
