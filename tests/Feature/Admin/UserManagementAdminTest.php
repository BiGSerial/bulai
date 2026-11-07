<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Users\Form;
use App\Livewire\Admin\Users\Index;
use App\Livewire\Admin\Users\Show;
use App\Models\User;
use App\Models\UserAuditEvent;
use App\Notifications\TemporaryPasswordNotification;
use App\Services\Users\UserAccountService;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_management_requires_an_admin_session(): void
    {
        $this->get('/admin/users')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/users')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/users')->assertOk()->assertSee('Usuários');
    }

    public function test_user_components_refuse_a_non_admin_even_without_the_route_middleware(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Index::class)->assertForbidden();
    }

    public function test_admin_creates_a_user_with_a_temporary_password_shown_only_once(): void
    {
        $admin = User::factory()->admin()->create();

        $component = Livewire::actingAs($admin)->test(Form::class)
            ->set('name', '  Maria   Souza ')
            ->set('email', ' Maria.Souza@Example.COM ')
            ->set('role', UserRole::User->value)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('não será mostrada novamente');
        $temporaryPassword = $this->shownTemporaryPassword($component);

        $user = User::query()->where('email', 'maria.souza@example.com')->sole();
        $this->assertSame('Maria Souza', $user->name);
        $this->assertSame(UserRole::User, $user->role);
        $this->assertTrue($user->isActive());
        $this->assertTrue($user->must_change_password);
        $this->assertSame($admin->id, $user->created_by);
        $this->assertSame(16, strlen($temporaryPassword));
        $this->assertNotSame($temporaryPassword, $user->password);
        $this->assertTrue(Hash::check($temporaryPassword, $user->password));

        $event = UserAuditEvent::query()->sole();
        $this->assertSame(UserAuditEvent::EVENT_CREATED, $event->event);
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertEquals(['email' => 'maria.souza@example.com', 'role' => 'USER'], $event->details);

        $component->call('$refresh')->assertDontSee($temporaryPassword);
        $component->call('finishCreation')->assertRedirect(route('admin.users.show', $user));
        $this->assertStringNotContainsString($temporaryPassword, serialize(session()->all()));
        $this->assertStringNotContainsString($temporaryPassword, json_encode(UserAuditEvent::query()->pluck('details')));
    }

    public function test_user_form_validates_required_fields_and_case_insensitive_duplicate_email(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'ana@example.com']);

        Livewire::actingAs($admin)->test(Form::class)
            ->set('email', 'não é e-mail')
            ->call('save')
            ->assertHasErrors(['name', 'email'])
            ->assertSee('Preencha o nome.')
            ->assertSee('Informe um e-mail válido.');

        Livewire::actingAs($admin)->test(Form::class)
            ->set('name', 'Outra Ana')
            ->set('email', 'ANA@Example.com')
            ->call('save')
            ->assertHasErrors(['email'])
            ->assertSee('Este e-mail já está cadastrado para outro usuário.');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_admin_edits_a_user_and_role_change_is_audited_and_revokes_access(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['name' => 'João', 'email' => 'joao@example.com', 'remember_token' => 'old-remember-token']);
        $this->insertSession('target-session', $user);

        Livewire::actingAs($admin)->test(Form::class, ['user' => $user])
            ->assertSet('email', 'joao@example.com')
            ->set('email', 'Joao.Silva@Example.com')
            ->set('role', UserRole::Admin->value)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.users.show', $user));

        $user->refresh();
        $this->assertSame('joao.silva@example.com', $user->email);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);

        $events = $user->auditEvents()->orderBy('id')->get();
        $this->assertSame([UserAuditEvent::EVENT_UPDATED, UserAuditEvent::EVENT_ROLE_CHANGED], $events->pluck('event')->all());
        $this->assertEquals(['changes' => ['email' => ['from' => 'joao@example.com', 'to' => 'joao.silva@example.com']]], $events[0]->details);
        $this->assertEquals(['from' => 'USER', 'to' => 'ADMIN'], $events[1]->details);
    }

    public function test_admin_cannot_deactivate_demote_or_reset_the_password_of_themselves(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(Index::class)
            ->call('toggleActive', $admin->id)
            ->assertHasErrors(['active'])
            ->assertSee('Você não pode desativar o seu próprio acesso.');

        Livewire::actingAs($admin)->test(Form::class, ['user' => $admin])
            ->set('role', UserRole::User->value)
            ->call('save')
            ->assertHasErrors(['role'])
            ->assertSee('Você não pode remover o seu próprio perfil de administrador.');

        Livewire::actingAs($admin)->test(Show::class, ['user' => $admin])
            ->call('issueTemporaryPassword')
            ->assertHasErrors(['temporaryPassword']);

        $admin->refresh();
        $this->assertTrue($admin->isActive());
        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($admin->must_change_password);
        $this->assertDatabaseCount('user_audit_events', 0);
    }

    public function test_the_last_active_admin_cannot_be_deactivated_or_demoted(): void
    {
        $lastAdmin = User::factory()->admin()->create();
        $actor = User::factory()->admin()->inactive()->create();
        $service = app(UserAccountService::class);

        $this->assertValidationMessage('active', 'É preciso manter pelo menos um administrador ativo.', fn () => $service->setActive($lastAdmin, false, $actor));
        $this->assertValidationMessage('role', 'É preciso manter pelo menos um administrador ativo.', fn () => $service->update($lastAdmin, [
            'name' => $lastAdmin->name,
            'email' => $lastAdmin->email,
            'role' => UserRole::User->value,
        ], $actor));

        $lastAdmin->refresh();
        $this->assertTrue($lastAdmin->isActive());
        $this->assertTrue($lastAdmin->isAdmin());
    }

    public function test_deactivating_a_user_revokes_database_sessions_and_remember_token(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['remember_token' => 'old-remember-token']);
        $otherUser = User::factory()->create();
        $this->insertSession('target-session-1', $user);
        $this->insertSession('target-session-2', $user);
        $this->insertSession('other-session', $otherUser);

        Livewire::actingAs($admin)->test(Index::class)
            ->call('toggleActive', $user->id)
            ->assertHasNoErrors()
            ->assertSee('Inativo');

        $user->refresh();
        $this->assertFalse($user->isActive());
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);

        Livewire::actingAs($admin)->test(Show::class, ['user' => $user])->call('toggleActive');

        $this->assertTrue($user->refresh()->isActive());
        $this->assertSame(
            [UserAuditEvent::EVENT_DEACTIVATED, UserAuditEvent::EVENT_REACTIVATED],
            $user->auditEvents()->orderBy('id')->pluck('event')->all(),
        );
    }

    public function test_issuing_a_temporary_password_replaces_the_hash_and_revokes_access(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['password' => 'old-test-password-1', 'remember_token' => 'old-remember-token']);
        $this->insertSession('target-session', $user);

        $component = Livewire::actingAs($admin)->test(Show::class, ['user' => $user])
            ->call('issueTemporaryPassword')
            ->assertHasNoErrors()
            ->assertSee('Nova senha provisória gerada');
        $temporaryPassword = $this->shownTemporaryPassword($component);

        $user->refresh();
        $this->assertTrue(Hash::check($temporaryPassword, $user->password));
        $this->assertFalse(Hash::check('old-test-password-1', $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);

        $event = $user->auditEvents()->sole();
        $this->assertSame(UserAuditEvent::EVENT_TEMPORARY_PASSWORD_ISSUED, $event->event);
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertSame([], $event->details);

        $component->call('$refresh')->assertDontSee($temporaryPassword);
        $this->assertStringNotContainsString($temporaryPassword, serialize(session()->all()));
    }

    public function test_users_list_searches_and_filters_by_status_and_role(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Ana Administradora', 'last_login_at' => '2026-10-19 08:30:00']);
        User::factory()->create(['name' => 'Bruno Ativo', 'email' => 'bruno@example.com']);
        User::factory()->inactive()->create(['name' => 'Carla Inativa']);

        Livewire::actingAs($admin)->test(Index::class)
            ->assertSee('Último acesso em 19/10/2026 08:30')
            ->assertSee('Nunca acessou')
            ->set('search', 'BRUNO@')
            ->assertSee('Bruno Ativo')
            ->assertDontSee('Carla Inativa')
            ->set('search', '')
            ->set('status', 'inactive')
            ->assertSee('Carla Inativa')
            ->assertDontSee('Bruno Ativo')
            ->set('status', 'all')
            ->set('role', UserRole::Admin->value)
            ->assertSee('Ana Administradora')
            ->assertDontSee('Bruno Ativo');
    }

    public function test_user_names_are_escaped_in_the_list_and_detail(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['name' => "O'Brien <script>alert('xss')</script>"]);

        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertDontSee("<script>alert('xss')</script>", false)->assertSee('&lt;script&gt;', false);
        $this->actingAs($admin)->get(route('admin.users.show', $user))->assertOk()->assertDontSee("<script>alert('xss')</script>", false);
    }

    public function test_detail_page_shows_the_audit_history_with_portuguese_labels(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Ana Administradora']);
        $service = app(UserAccountService::class);
        [$user] = $service->create(['name' => 'Bruno', 'email' => 'bruno@example.com', 'role' => UserRole::User->value], $admin);
        $service->update($user, ['name' => 'Bruno Lima', 'email' => 'bruno@example.com', 'role' => UserRole::Admin->value], $admin);
        $service->completeFirstAccess($user, 'own-test-pass-1', null);

        Livewire::actingAs($admin)->test(Show::class, ['user' => $user])
            ->assertSee('Usuário cadastrado')
            ->assertSee('Perfil: Usuário')
            ->assertSee('Dados alterados')
            ->assertSee('Nome: Bruno → Bruno Lima')
            ->assertSee('Perfil alterado')
            ->assertSee('Usuário → Administrador')
            ->assertSee('Senha trocada pela própria pessoa')
            ->assertSee('Ao substituir a senha provisória.')
            ->assertSee('Cadastrado por')
            ->assertSee('Ana Administradora');
    }

    public function test_admin_can_email_the_temporary_password_when_creating_and_when_reissuing(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        $component = Livewire::actingAs($admin)->test(Form::class)
            ->set('name', 'Maria Souza')->set('email', 'maria@example.com')->set('role', UserRole::User->value)->set('sendByEmail', true)
            ->call('save')->assertHasNoErrors();
        $password = $this->shownTemporaryPassword($component);
        $user = User::query()->where('email', 'maria@example.com')->sole();
        Notification::assertSentTo($user, TemporaryPasswordNotification::class, fn (TemporaryPasswordNotification $n): bool => str_contains((string) $n->toMail($user)->render(), $password));
        $this->assertSame([UserAuditEvent::EVENT_CREATED, UserAuditEvent::EVENT_TEMPORARY_PASSWORD_EMAILED], $user->auditEvents()->orderBy('id')->pluck('event')->all());

        Livewire::actingAs($admin)->test(Show::class, ['user' => $user])->set('sendByEmail', true)->call('issueTemporaryPassword')->assertHasNoErrors();
        Notification::assertSentToTimes($user, TemporaryPasswordNotification::class, 2);
        $this->assertStringNotContainsString($password, json_encode(UserAuditEvent::query()->get()));
    }

    public function test_deactivation_with_a_reason_forced_password_change_and_session_termination_are_audited(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['remember_token' => 'old-remember-token']);
        $this->insertSession('target-session', $user);

        $component = Livewire::actingAs($admin)->test(Show::class, ['user' => $user]);

        $component->call('forcePasswordChange')->assertHasNoErrors()->assertSee('precisará definir uma nova senha');
        $this->assertTrue($user->refresh()->must_change_password);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);

        $this->insertSession('target-session-2', $user);
        $component->call('terminateSessions')->assertHasNoErrors()->assertSee('As sessões foram encerradas.');
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session-2']);

        $component->set('reason', '  Desligamento   da equipe ')->call('toggleActive')->assertHasNoErrors()->assertSee('Motivo: Desligamento da equipe');
        $this->assertFalse($user->refresh()->isActive());
        $this->assertSame('', $component->get('reason'));

        $this->assertSame(
            [UserAuditEvent::EVENT_PASSWORD_CHANGE_FORCED, UserAuditEvent::EVENT_SESSIONS_TERMINATED, UserAuditEvent::EVENT_DEACTIVATED],
            $user->auditEvents()->orderBy('id')->pluck('event')->all(),
        );
        $this->assertSame(['reason' => 'Desligamento da equipe'], $user->auditEvents()->orderByDesc('id')->first()->details);
        $this->assertSame(['scope' => 'all'], $user->auditEvents()->where('event', UserAuditEvent::EVENT_SESSIONS_TERMINATED)->sole()->details);

        $component->assertSee('Troca de senha exigida no próximo acesso')->assertSee('Sessões encerradas')->assertSee('Acesso desativado');
    }

    public function test_admin_cannot_force_their_own_password_change_and_the_detail_shows_usage_counts_without_health_content(): void
    {
        $admin = User::factory()->admin()->create();
        $component = Livewire::actingAs($admin)->test(Show::class, ['user' => $admin]);

        $this->assertValidationMessage('mustChangePassword', 'Para trocar a sua própria senha, use Minha conta.', fn () => app(UserAccountService::class)->forcePasswordChange($admin, $admin));
        $component->assertSee('Uso do aplicativo')->assertSee('Consultas de interação')->assertSee('Sem consentimento')->assertDontSee('Exigir troca de senha');
    }

    public function test_users_list_sorts_filters_pending_password_changes_and_shows_totals(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Zelia Admin', 'last_login_at' => now()->subDay()]);
        User::factory()->mustChangePassword()->create(['name' => 'Ana Pendente', 'last_login_at' => null]);
        User::factory()->create(['name' => 'Bruno Recente', 'last_login_at' => now()]);

        Livewire::actingAs($admin)->test(Index::class)
            ->assertSee('3 usuários encontrados')
            ->assertSeeInOrder(['Ana Pendente', 'Bruno Recente', 'Zelia Admin'])
            ->set('sort', 'recent_login')->assertSeeInOrder(['Bruno Recente', 'Zelia Admin', 'Ana Pendente'])
            ->set('status', 'pending')->assertSee('1 usuário encontrado')->assertSee('Ana Pendente')->assertDontSee('Bruno Recente')
            ->assertSee('Só um administrador');
    }

    public function test_a_mail_transport_failure_still_shows_the_temporary_password_with_a_warning_instead_of_a_500(): void
    {
        // Transporte real da KingHost sem token: o envio é recusado antes de qualquer chamada HTTP.
        config(['mail.default' => 'kinghost', 'services.kinghost_smtp.token' => null]);
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $component = Livewire::actingAs($admin)->test(Show::class, ['user' => $user])->set('sendByEmail', true)->call('issueTemporaryPassword')
            ->assertHasErrors('temporaryPassword')
            ->assertSee('data-testid="temporary-password-warning"', false)
            ->assertSee('o e-mail não pôde ser enviado')
            ->assertSee('token da API não configurado');
        $this->shownTemporaryPassword($component);
        $this->assertSame([UserAuditEvent::EVENT_TEMPORARY_PASSWORD_ISSUED], $user->auditEvents()->pluck('event')->all(), 'o envio não é auditado quando falha');

        $created = Livewire::actingAs($admin)->test(Form::class)
            ->set('name', 'João Lima')->set('email', 'joao@example.com')->set('role', UserRole::User->value)->set('sendByEmail', true)
            ->call('save')
            ->assertHasErrors('temporaryPassword')
            ->assertSee('data-testid="temporary-password-warning"', false);
        $this->shownTemporaryPassword($created);
        $this->assertTrue(User::query()->where('email', 'joao@example.com')->exists(), 'o usuário é criado mesmo com o e-mail recusado');
    }

    private function shownTemporaryPassword(Testable $component): string
    {
        $this->assertSame(1, preg_match('/data-temporary-password>([A-Za-z0-9]+)</', $component->html(), $matches), 'A senha provisória deve aparecer na tela.');

        return $matches[1];
    }

    private function insertSession(string $id, User $user): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    private function assertValidationMessage(string $field, string $message, callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->assertSame([$message], $exception->errors()[$field] ?? null);

            return;
        }

        $this->fail("Era esperada uma ValidationException em {$field}.");
    }

    public function test_users_list_sorts_by_column_headers_and_the_second_click_inverts_the_direction(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Zelia Admin', 'email' => 'a-zelia@example.com']);
        User::factory()->create(['name' => 'Ana', 'email' => 'c-ana@example.com']);
        User::factory()->create(['name' => 'Bruno', 'email' => 'b-bruno@example.com']);

        Livewire::actingAs($admin)->test(Index::class)
            ->call('sortBy', 'email')->assertSet('sort', 'email')->assertSeeInOrder(['Zelia Admin', 'Bruno', 'Ana'])
            ->call('sortBy', 'email')->assertSet('direction', 'desc')->assertSeeInOrder(['Ana', 'Bruno', 'Zelia Admin'])
            ->call('sortBy', 'inexistente')->assertSet('sort', 'email')
            ->set('search', 'ana')->assertDontSee('Bruno')->call('clearFilters')->assertSet('search', '')->assertSee('Bruno');
    }

    public function test_user_detail_has_tabs_and_an_unknown_tab_falls_back_to_the_first_one(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['name' => 'Carla']);

        Livewire::actingAs($admin)->test(Show::class, ['user' => $user])
            ->assertSet('tab', 'dados')->assertSeeHtml('id="painel-acesso"')->assertSeeHtml('id="painel-historico"')
            ->set('tab', 'historico')->assertSet('tab', 'historico')->assertSee('Nenhum evento registrado para este acesso.')
            ->set('tab', 'qualquer')->assertSet('tab', 'dados');
    }
}
