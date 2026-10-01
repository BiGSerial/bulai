<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_logout_invalidates_session_and_redirects_to_login(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirectToRoute('login');
        $this->assertGuest();
    }
}
