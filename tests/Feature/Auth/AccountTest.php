<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserAuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_page_requires_authentication_and_shows_the_profile(): void
    {
        $this->get('/account')->assertRedirectToRoute('login');

        $user = User::factory()->create(['name' => 'Bruno Lima', 'email' => 'bruno@example.com']);
        $this->actingAs($user)->get('/account')
            ->assertOk()
            ->assertSee('Minha conta')
            ->assertSee('Bruno Lima')
            ->assertSee('bruno@example.com')
            ->assertSee('Usuário')
            ->assertDontSee('Ir para a área administrativa');

        $this->actingAs(User::factory()->admin()->create())->get('/account')
            ->assertOk()
            ->assertSee('Administrador')
            ->assertSee('Ir para a área administrativa');
    }

    public function test_password_change_rejects_a_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => 'current-test-pass-1']);

        $this->actingAs($user)->from('/account')->put('/account/password', [
            'current_password' => 'wrong-test-pass-1',
            'password' => 'brand-new-test-pass-2',
            'password_confirmation' => 'brand-new-test-pass-2',
        ])->assertRedirect('/account')->assertSessionHasErrors(['current_password' => 'A senha atual está incorreta.']);

        $this->assertTrue(Hash::check('current-test-pass-1', $user->refresh()->password));
    }

    public function test_password_change_rejects_a_new_password_equal_to_the_current_one(): void
    {
        $user = User::factory()->create(['password' => 'current-test-pass-1']);

        $this->actingAs($user)->from('/account')->put('/account/password', [
            'current_password' => 'current-test-pass-1',
            'password' => 'current-test-pass-1',
            'password_confirmation' => 'current-test-pass-1',
        ])->assertSessionHasErrors(['password' => 'A nova senha deve ser diferente da senha atual.']);
    }

    #[DataProvider('weakPasswords')]
    public function test_password_change_requires_twelve_characters_with_letters_and_numbers(string $password, string $message): void
    {
        $user = User::factory()->create(['password' => 'current-test-pass-1']);

        $this->actingAs($user)->from('/account')->put('/account/password', [
            'current_password' => 'current-test-pass-1',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertSessionHasErrors(['password' => $message]);

        $this->assertTrue(Hash::check('current-test-pass-1', $user->refresh()->password));
    }

    /** @return array<string, array{string, string}> */
    public static function weakPasswords(): array
    {
        return [
            'curta' => ['short-pass1', 'O campo nova senha deve ter pelo menos 12 caracteres.'],
            'sem números' => ['only-letters-here', 'O campo nova senha deve conter pelo menos um número.'],
            'sem letras' => ['1234-5678-9012', 'O campo nova senha deve conter pelo menos uma letra.'],
        ];
    }

    public function test_voluntary_password_change_revokes_other_sessions_and_keeps_the_current_one(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create([
            'password' => 'current-test-pass-1',
            'remember_token' => 'old-remember-token',
        ]);
        DB::table('sessions')->insert([
            'id' => 'other-device-session',
            'user_id' => $user->id,
            'ip_address' => '10.0.0.2',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'current-test-pass-1',
            'password' => 'my-own-test-pass-2',
            'password_confirmation' => 'my-own-test-pass-2',
        ])->assertRedirectToRoute('account')->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertTrue(Hash::check('my-own-test-pass-2', $user->password));
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
        $this->assertAuthenticatedAs($user);
        $this->get('/account')->assertOk()->assertSee('Senha alterada com sucesso.');

        $event = $user->auditEvents()->sole();
        $this->assertSame(UserAuditEvent::EVENT_PASSWORD_CHANGED, $event->event);
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame([], $event->details);
    }
}
