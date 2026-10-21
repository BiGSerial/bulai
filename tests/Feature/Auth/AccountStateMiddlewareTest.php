<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountStateMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_user_with_an_open_session_is_logged_out(): void
    {
        $admin = User::factory()->admin()->inactive()->create();

        $this->actingAs($admin)->get('/admin/users')
            ->assertRedirectToRoute('login')
            ->assertSessionHasErrors(['email' => 'Este acesso está desativado. Procure um administrador.']);
        $this->assertGuest();

        $this->actingAs(User::factory()->inactive()->create())->get('/account')->assertRedirectToRoute('login');
        $this->assertGuest();
    }

    public function test_admin_with_pending_password_change_is_sent_to_first_access(): void
    {
        $admin = User::factory()->admin()->mustChangePassword()->create();

        $this->actingAs($admin)->get('/admin/documents')->assertRedirectToRoute('first-access');
        $this->actingAs($admin)->get('/account')->assertRedirectToRoute('first-access');
        $this->actingAs($admin)->get('/first-access')->assertOk()->assertSee('Sua senha');
    }

    public function test_pending_password_change_cannot_be_done_through_my_account(): void
    {
        $user = User::factory()->mustChangePassword()->create(['password' => 'temporary-test-pass-1']);

        $this->actingAs($user)->get('/account')->assertRedirectToRoute('first-access');
        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'temporary-test-pass-1',
            'password' => 'my-own-test-pass-2',
            'password_confirmation' => 'my-own-test-pass-2',
        ])->assertRedirectToRoute('first-access');

        $user->refresh();
        $this->assertTrue(Hash::check('temporary-test-pass-1', $user->password));
        $this->assertTrue($user->must_change_password);
    }

    public function test_user_role_keeps_receiving_403_in_admin_even_with_pending_password_change(): void
    {
        $this->actingAs(User::factory()->mustChangePassword()->create())->get('/admin/documents')->assertForbidden();
    }

    public function test_root_redirect_depends_on_role_and_pending_password_change(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertRedirectToRoute('account');
        $this->actingAs(User::factory()->mustChangePassword()->create())->get('/')->assertRedirectToRoute('first-access');
        $this->actingAs(User::factory()->admin()->mustChangePassword()->create())->get('/')->assertRedirectToRoute('first-access');
        $this->actingAs(User::factory()->admin()->create())->get('/')->assertRedirectToRoute('admin.documents.index');
    }
}
