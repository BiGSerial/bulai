<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\UserRole;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->setAdminEnvironment(null, null, null);

        parent::tearDown();
    }

    public function test_database_seeder_creates_configured_admin_user(): void
    {
        $this->setAdminEnvironment('BULA-IA Admin', 'admin@example.com', 'secret-password');

        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertSame('BULA-IA Admin', $admin->name);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertCredentials([
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ]);
    }

    public function test_database_seeder_skips_admin_without_explicit_credentials(): void
    {
        $this->setAdminEnvironment(null, null, null);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }

    private function setAdminEnvironment(?string $name, ?string $email, ?string $password): void
    {
        foreach ([
            'BULAI_ADMIN_NAME' => $name,
            'BULAI_ADMIN_EMAIL' => $email,
            'BULAI_ADMIN_PASSWORD' => $password,
        ] as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
