<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PasswordHashingTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_passwords_are_hashed_with_argon2id_using_the_configured_parameters(): void
    {
        $this->assertSame('argon2id', config('hashing.driver'));

        $user = User::factory()->create(['password' => 'my-own-test-pass-1']);

        $this->assertStringStartsWith('$argon2id$', $user->password);
        $this->assertTrue(password_verify('my-own-test-pass-1', $user->password));
        $this->assertTrue(Hash::check('my-own-test-pass-1', $user->password));
        $info = password_get_info($user->password);
        $this->assertSame(['memory_cost' => config('hashing.argon.memory'), 'time_cost' => config('hashing.argon.time'), 'threads' => config('hashing.argon.threads')], $info['options']);
    }

    public function test_legacy_bcrypt_hashes_still_authenticate_and_are_rehashed_to_argon2id_on_login(): void
    {
        $bcrypt = password_hash('legacy-test-pass-1', PASSWORD_BCRYPT, ['cost' => 4]);
        $user = User::factory()->create();
        User::query()->whereKey($user->id)->update(['password' => $bcrypt]);
        $this->assertStringStartsWith('$2y$', $user->refresh()->password);

        $this->post('/login', ['email' => $user->email, 'password' => 'legacy-test-pass-1'])->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertStringStartsWith('$argon2id$', $user->refresh()->password);
        $this->assertTrue(Hash::check('legacy-test-pass-1', $user->password));
    }
}
