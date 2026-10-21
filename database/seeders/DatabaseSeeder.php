<?php

namespace Database\Seeders;

use App\Models\User;
use App\UserRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $adminEmail = env('BULAI_ADMIN_EMAIL');
        $adminPassword = env('BULAI_ADMIN_PASSWORD');
        $adminEmail = is_string($adminEmail) ? Str::lower(trim($adminEmail)) : null;

        if ($adminEmail === null || $adminEmail === '' || ! is_string($adminPassword) || $adminPassword === '') {
            return;
        }

        // O admin do seed é o acesso de recuperação: fica sempre ativo e sem troca de senha obrigatória.
        User::query()->updateOrCreate(
            ['email' => $adminEmail],
            [
                'name' => env('BULAI_ADMIN_NAME', 'BULA-IA Admin'),
                'password' => $adminPassword,
                'role' => UserRole::Admin,
                'active' => true,
                'must_change_password' => false,
                'email_verified_at' => now(),
            ],
        );
    }
}
