<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Normaliza os e-mails existentes para minúsculas (o login e a gestão de usuários passam a comparar
     * e-mails sem diferenciar maiúsculas) e acrescenta o estado da conta. Aborta, sem alterar nada, se
     * houver dois cadastros com o mesmo e-mail ignorando maiúsculas: a unificação é decisão humana.
     */
    public function up(): void
    {
        $this->normalizeEmails();

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('active')->default(true)->after('role')->index();
            $table->boolean('must_change_password')->default(false)->after('active');
            $table->foreignId('created_by')->nullable()->after('must_change_password')->constrained('users')->nullOnDelete();
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
        });
    }

    /**
     * Reverse the migrations.
     *
     * A normalização dos e-mails não é desfeita: a caixa original não é guardada.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropIndex(['active']);
            $table->dropColumn(['active', 'must_change_password', 'last_login_at']);
        });
    }

    private function normalizeEmails(): void
    {
        $users = DB::table('users')->orderBy('id')->get(['id', 'email']);
        $idsByEmail = $users->groupBy(fn (object $user): string => $this->normalize((string) $user->email))
            ->map(fn ($group) => $group->pluck('id')->all());

        $duplicates = $idsByEmail->filter(fn (array $ids): bool => count($ids) > 1);
        if ($duplicates->isNotEmpty()) {
            $groups = $duplicates->map(fn (array $ids): string => 'ids '.implode(', ', $ids))->implode('; ');

            throw new RuntimeException(
                "Há usuários com o mesmo e-mail ignorando maiúsculas/minúsculas ({$groups}). "
                .'Unifique ou altere esses cadastros e rode a migration novamente; nada foi alterado.'
            );
        }

        foreach ($users as $user) {
            $email = $this->normalize((string) $user->email);
            if ($email !== $user->email) {
                DB::table('users')->where('id', $user->id)->update(['email' => $email]);
            }
        }
    }

    private function normalize(string $email): string
    {
        return Str::lower(trim($email));
    }
};
