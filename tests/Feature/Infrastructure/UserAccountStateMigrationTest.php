<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * A migration roda dentro da transação do RefreshDatabase (DDL é transacional no PostgreSQL),
 * então o esquema volta ao estado migrado ao fim de cada teste.
 */
class UserAccountStateMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_aborts_without_changes_when_emails_collide_ignoring_case(): void
    {
        $migration = $this->rolledBackMigration();
        $this->insertLegacyUsers(['Ana@Example.com', 'ana@example.com ']);

        try {
            $migration->up();
            $this->fail('A migration deveria abortar com e-mails duplicados.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('mesmo e-mail ignorando maiúsculas/minúsculas', $exception->getMessage());
            $this->assertStringNotContainsString('example.com', $exception->getMessage());
        }

        $this->assertSame('Ana@Example.com', DB::table('users')->orderBy('id')->value('email'));
        $this->assertFalse(Schema::hasColumn('users', 'active'));
    }

    public function test_migration_normalizes_emails_and_adds_active_account_state(): void
    {
        $migration = $this->rolledBackMigration();
        $this->insertLegacyUsers([' Ana@Example.com', 'CARLOS@example.COM']);

        $migration->up();

        $this->assertSame(['ana@example.com', 'carlos@example.com'], DB::table('users')->orderBy('id')->pluck('email')->all());
        $this->assertSame([true], DB::table('users')->pluck('active')->unique()->values()->all());
        $this->assertSame([false], DB::table('users')->pluck('must_change_password')->unique()->values()->all());
    }

    private function rolledBackMigration(): Migration
    {
        Schema::dropIfExists('user_audit_events');
        $migration = require database_path('migrations/2026_10_20_164519_add_account_state_to_users_table.php');
        $migration->down();

        return $migration;
    }

    /** @param array<int, string> $emails */
    private function insertLegacyUsers(array $emails): void
    {
        foreach ($emails as $index => $email) {
            DB::table('users')->insert(['name' => "Pessoa {$index}", 'email' => $email, 'password' => 'not-a-real-hash', 'role' => 'USER']);
        }
    }
}
