<?php

namespace Database\Seeders;

use App\Models\User;
use App\UserRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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

        if (! is_string($adminEmail) || $adminEmail === '' || ! is_string($adminPassword) || $adminPassword === '') {
            return;
        }

        User::query()->updateOrCreate(
            ['email' => $adminEmail],
            [
                'name' => env('BULAI_ADMIN_NAME', 'BULA-IA Admin'),
                'password' => $adminPassword,
                'role' => UserRole::Admin,
                'email_verified_at' => now(),
            ],
        );
    }
}
