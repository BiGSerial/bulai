<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_from_admin_routes(): void
    {
        $this
            ->get('/admin/documents')
            ->assertRedirectToRoute('login');
    }

    public function test_user_role_is_forbidden_from_admin_routes(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->get('/admin/documents')
            ->assertForbidden();
    }

    public function test_admin_role_can_open_admin_documents(): void
    {
        $admin = User::factory()->admin()->create();

        $this
            ->actingAs($admin)
            ->get('/admin/documents')
            ->assertOk()
            ->assertSee('Documentos');
    }
}
