<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders_for_guests(): void
    {
        $this
            ->get('/login')
            ->assertOk()
            ->assertSee('Área administrativa')
            ->assertSee('/brand/bulai-logo-horizontal-transparente.svg', false);
    }

    public function test_valid_credentials_authenticate_user_and_redirect_to_admin_documents(): void
    {
        $user = User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ]);

        $response = $this->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertRedirectToRoute('admin.documents.index');
        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_return_validation_error_and_keep_guest(): void
    {
        User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ]);

        $response
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_accepts_email_with_different_case_and_records_last_login(): void
    {
        $this->travelTo('2026-10-20 09:15:00');
        $user = User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ]);

        $this->post('/login', [
            'email' => ' Admin@Example.COM ',
            'password' => 'secret-password',
        ])->assertRedirectToRoute('admin.documents.index');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('2026-10-20 09:15:00', $user->refresh()->last_login_at->format('Y-m-d H:i:s'));
    }

    public function test_sixth_login_attempt_in_a_minute_is_blocked_with_message_on_the_form(): void
    {
        $this->freezeTime();
        User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->from('/login')->post('/login', [
                'email' => $attempt % 2 === 0 ? 'ADMIN@example.com' : 'admin@example.com',
                'password' => 'wrong-password',
            ])->assertRedirect('/login');
        }

        $this->followingRedirects()->from('/login')->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ])
            ->assertOk()
            ->assertSee('Área administrativa')
            ->assertSee('Muitas tentativas. Tente novamente em 60 segundos.');

        $this->assertGuest();
    }

    public function test_login_with_a_non_text_email_is_a_validation_error_instead_of_a_server_error(): void
    {
        $this->from('/login')->post('/login', [
            'email' => ['admin@example.com'],
            'password' => 'secret-password',
        ])->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deactivated_user_cannot_log_in_and_only_learns_it_with_the_right_password(): void
    {
        User::factory()->admin()->inactive()->create([
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ]);

        $this->from('/login')->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors(['email' => 'E-mail ou senha incorretos.']);

        $this->from('/login')->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ])->assertSessionHasErrors(['email' => 'Este acesso está desativado. Procure um administrador.']);

        $this->assertGuest();
    }

    public function test_user_role_is_sent_to_account_even_when_an_admin_page_was_intended(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'secret-password',
        ]);

        $this->get('/admin/documents')->assertRedirectToRoute('login');

        $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'secret-password',
        ])->assertRedirectToRoute('account');
    }

    #[DataProvider('roles')]
    public function test_pending_password_change_sends_to_first_access_after_login_even_when_an_admin_page_was_intended(UserRole $role): void
    {
        User::factory()->mustChangePassword()->create([
            'email' => 'person@example.com',
            'password' => 'temporary-test-pass-1',
            'role' => $role,
        ]);

        $this->get('/admin/users')->assertRedirectToRoute('login');

        $this->post('/login', [
            'email' => 'person@example.com',
            'password' => 'temporary-test-pass-1',
        ])->assertRedirectToRoute('first-access')->assertSessionMissing('url.intended');
    }

    /** @return array<string, array{UserRole}> */
    public static function roles(): array
    {
        return [
            'administrador' => [UserRole::Admin],
            'usuário' => [UserRole::User],
        ];
    }

    public function test_logout_invalidates_session_and_redirects_to_login(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirectToRoute('login');
        $this->assertGuest();
    }
}
