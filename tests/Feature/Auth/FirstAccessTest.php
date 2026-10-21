<?php

namespace Tests\Feature\Auth;

use App\AdminNavigation;
use App\Models\User;
use App\Models\UserAuditEvent;
use App\Services\Users\UserAccountService;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FirstAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_access_requires_an_authenticated_and_active_account(): void
    {
        $this->get('/first-access')->assertRedirectToRoute('login');

        $this->actingAs(User::factory()->mustChangePassword()->inactive()->create())->get('/first-access')
            ->assertRedirectToRoute('login')
            ->assertSessionHasErrors(['email' => 'Este acesso está desativado. Procure um administrador.']);
        $this->assertGuest();
    }

    public function test_person_without_pending_password_change_is_sent_to_their_home(): void
    {
        $this->actingAs(User::factory()->create())->get('/first-access')->assertRedirectToRoute('account');
        $this->actingAs(User::factory()->admin()->create())->get('/first-access')->assertRedirectToRoute('admin.documents.index');
    }

    public function test_password_cannot_be_set_here_without_a_pending_password_change(): void
    {
        $user = User::factory()->create(['password' => 'current-test-pass-1']);

        $this->actingAs($user)->put('/first-access/password', [
            'password' => 'my-own-test-pass-2',
            'password_confirmation' => 'my-own-test-pass-2',
        ])->assertRedirectToRoute('account');

        $this->assertTrue(Hash::check('current-test-pass-1', $user->refresh()->password));
        $this->assertSame(0, $user->auditEvents()->count());
    }

    public function test_welcome_for_an_admin_presents_the_system_and_every_menu_area(): void
    {
        $admin = User::factory()->admin()->mustChangePassword()->create(['name' => 'Ana Souza']);

        $response = $this->actingAs($admin)->get('/first-access')
            ->assertOk()
            ->assertSee('Boas-vindas ao BULAI, Ana Souza')
            ->assertSee('reúne bulas de medicamentos')
            ->assertSee('O que você encontra na área administrativa')
            ->assertSee('Pendências')
            ->assertSee('Arquivos que precisam da sua decisão antes de entrar na base')
            ->assertDontSee('Defina sua nova senha')
            ->assertDontSee('A consulta de bulas ainda está em preparação');

        // Mesma fonte do menu do shell: todo item do menu aparece no resumo.
        foreach (AdminNavigation::items() as $area) {
            $response->assertSee($area['label'])->assertSee($area['hint']);
        }
    }

    public function test_welcome_for_a_user_explains_my_account_and_announces_leaflet_search(): void
    {
        $user = User::factory()->mustChangePassword()->create(['name' => 'Bruno <i>Lima</i>']);

        $this->actingAs($user)->get('/first-access')
            ->assertOk()
            ->assertSee('Boas-vindas ao BULAI, Bruno &lt;i&gt;Lima&lt;/i&gt;', false)
            ->assertSee('Ver os seus dados de acesso (nome, e-mail e perfil) em Minha conta.')
            ->assertSee('Trocar a sua senha sempre que quiser')
            ->assertSee('A consulta de bulas ainda está em preparação')
            ->assertDontSee('O que você encontra na área administrativa')
            ->assertDontSee('Arquivos que precisam da sua decisão antes de entrar na base');
    }

    public function test_new_temporary_password_for_someone_who_already_set_a_password_skips_the_welcome(): void
    {
        $service = app(UserAccountService::class);
        $user = User::factory()->admin()->create(['name' => 'Carla Dias']);
        $service->changePassword($user, 'own-test-pass-1', null);
        $service->issueTemporaryPassword($user, User::factory()->admin()->create());

        $this->actingAs($user->refresh())->get('/first-access')
            ->assertOk()
            ->assertSee('Defina sua nova senha')
            ->assertSee('Um administrador gerou uma senha provisória para a sua conta.')
            ->assertDontSee('Boas-vindas')
            ->assertDontSee('O que você encontra na área administrativa');
    }

    #[DataProvider('invalidNewPasswords')]
    public function test_new_password_is_validated_and_must_differ_from_the_temporary_one(string $password, string $confirmation, string $message): void
    {
        $user = User::factory()->mustChangePassword()->create(['password' => 'temporary-test-pass-1']);

        $this->actingAs($user)->from('/first-access')->put('/first-access/password', [
            'password' => $password,
            'password_confirmation' => $confirmation,
        ])->assertRedirect('/first-access')->assertSessionHasErrors(['password' => $message]);

        $user->refresh();
        $this->assertTrue(Hash::check('temporary-test-pass-1', $user->password));
        $this->assertTrue($user->must_change_password);
    }

    /** @return array<string, array{string, string, string}> */
    public static function invalidNewPasswords(): array
    {
        return [
            'fraca' => ['short-pass1', 'short-pass1', 'O campo nova senha deve ter pelo menos 12 caracteres.'],
            'confirmação diferente' => ['my-own-test-pass-2', 'other-test-pass-3', 'A confirmação de nova senha não confere.'],
            'igual à provisória' => ['temporary-test-pass-1', 'temporary-test-pass-1', 'A nova senha deve ser diferente da senha provisória.'],
        ];
    }

    #[DataProvider('destinations')]
    public function test_setting_the_password_clears_the_flag_revokes_other_sessions_and_sends_to_the_role_home(UserRole $role, string $routeName, string $home): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->mustChangePassword()->create([
            'role' => $role,
            'password' => 'temporary-test-pass-1',
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

        $this->actingAs($user)->put('/first-access/password', [
            'password' => 'my-own-test-pass-2',
            'password_confirmation' => 'my-own-test-pass-2',
        ])->assertRedirectToRoute($routeName)->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertTrue(Hash::check('my-own-test-pass-2', $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
        $this->assertAuthenticatedAs($user);
        $this->get($home)->assertOk()->assertSee('Senha definida com sucesso.');

        $event = $user->auditEvents()->sole();
        $this->assertSame(UserAuditEvent::EVENT_PASSWORD_CHANGED, $event->event);
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame(['via' => 'first_access'], $event->details);
    }

    /** @return array<string, array{UserRole, string, string}> */
    public static function destinations(): array
    {
        return [
            'administrador vai à área administrativa' => [UserRole::Admin, 'admin.documents.index', '/admin/documents'],
            'usuário vai à Minha conta' => [UserRole::User, 'account', '/account'],
        ];
    }
}
