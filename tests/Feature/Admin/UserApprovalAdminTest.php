<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Users\Form;
use App\Livewire\Admin\Users\Index;
use App\Livewire\Admin\Users\Show;
use App\Models\User;
use App\Models\UserAuditEvent;
use App\Notifications\AccountApprovedNotification;
use App\UserApprovalStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Aprovação dos cadastros feitos pelo site: quem pode entrar, quem decide e o e-mail de aviso. */
class UserApprovalAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_and_rejected_accounts_cannot_sign_in_and_get_a_clear_message_only_with_the_right_password(): void
    {
        $pending = User::factory()->awaitingApproval()->create(['email' => 'pendente@example.com', 'password' => 'senha-segura-2026']);
        User::factory()->rejected()->create(['email' => 'recusado@example.com', 'password' => 'senha-segura-2026']);

        $this->from('/login')->post('/login', ['email' => 'pendente@example.com', 'password' => 'senha-segura-2026'])
            ->assertRedirect('/login')->assertSessionHasErrors(['email' => __('auth.awaiting_approval')]);
        $this->assertGuest();

        $this->from('/login')->post('/login', ['email' => 'recusado@example.com', 'password' => 'senha-segura-2026'])
            ->assertSessionHasErrors(['email' => __('auth.rejected')]);
        $this->assertGuest();

        // Senha errada: mensagem genérica, sem revelar que o cadastro existe e está pendente.
        $this->from('/login')->post('/login', ['email' => 'pendente@example.com', 'password' => 'senha-errada-2026'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertNull($pending->fresh()->last_login_at);
    }

    public function test_an_unapproved_session_is_ended_by_the_account_state_middleware(): void
    {
        $pending = User::factory()->awaitingApproval()->create();

        $this->actingAs($pending)->get(route('consulta.inicio'))
            ->assertRedirectToRoute('login')
            ->assertSessionHasErrors(['email' => __('auth.awaiting_approval')]);
        $this->assertGuest();
    }

    public function test_existing_and_admin_created_users_are_approved_and_keep_signing_in(): void
    {
        Notification::fake();
        $legacy = User::factory()->create(['email' => 'antigo@example.com', 'password' => 'senha-segura-2026']);
        $this->assertSame(UserApprovalStatus::Approved, $legacy->fresh()->approval_status);

        $this->post('/login', ['email' => 'antigo@example.com', 'password' => 'senha-segura-2026'])->assertRedirectToRoute('consulta.inicio');
        $this->assertAuthenticatedAs($legacy);
        auth()->logout();

        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(Form::class)
            ->set('name', 'Carlos Lima')->set('email', 'carlos@example.com')->set('role', UserRole::User->value)
            ->call('save')->assertHasNoErrors();
        $created = User::query()->where('email', 'carlos@example.com')->sole();
        $this->assertSame(UserApprovalStatus::Approved, $created->approval_status);
        $this->assertSame($admin->id, $created->approval_reviewed_by);
        $this->assertNotNull($created->approval_reviewed_at);
    }

    public function test_admin_approves_a_pending_registration_from_the_list_and_the_person_gets_the_approval_email(): void
    {
        Notification::fake();
        $this->travelTo('2026-11-09 14:30:00');
        $admin = User::factory()->admin()->create();
        $pending = User::factory()->awaitingApproval()->create(['name' => 'Ana Pendente', 'email' => 'ana@example.com', 'password' => 'senha-segura-2026']);

        Livewire::actingAs($admin)->test(Index::class)
            ->assertSee('1 cadastro aguardando aprovação.')
            ->assertSee('Aguardando aprovação')
            ->call('approve', $pending->id)
            ->assertHasNoErrors()
            ->assertSee('Cadastro de Ana Pendente aprovado. Avisamos a pessoa por e-mail.');

        $pending->refresh();
        $this->assertSame(UserApprovalStatus::Approved, $pending->approval_status);
        $this->assertSame($admin->id, $pending->approval_reviewed_by);
        $this->assertTrue($pending->approval_reviewed_at->equalTo(now()));
        $this->assertSame(
            [UserAuditEvent::EVENT_REGISTRATION_APPROVED, UserAuditEvent::EVENT_APPROVAL_EMAILED],
            $pending->auditEvents()->orderBy('id')->pluck('event')->all(),
        );
        Notification::assertSentTo($pending, AccountApprovedNotification::class, function (AccountApprovedNotification $notification) use ($pending): bool {
            $mail = $notification->toMail($pending);
            $html = (string) $mail->render();

            return $mail->subject === 'Seu acesso foi liberado · '.config('app.name')
                && str_contains($html, 'Ana Pendente')
                && str_contains($html, route('login'))
                && str_contains($html, 'Trabalho de Conclusão de Curso');
        });

        auth()->logout();
        $this->post('/login', ['email' => 'ana@example.com', 'password' => 'senha-segura-2026'])->assertRedirectToRoute('consulta.inicio');
        $this->assertAuthenticatedAs($pending);
    }

    public function test_admin_rejects_a_registration_with_a_reason_and_can_approve_it_later_from_the_detail_page(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create(['name' => 'Rita Admin']);
        $pending = User::factory()->awaitingApproval()->create();

        Livewire::actingAs($admin)->test(Show::class, ['user' => $pending])
            ->assertSee('Cadastro aguardando aprovação.')
            ->set('rejectionReason', 'Fora do público do piloto')
            ->call('reject')
            ->assertHasNoErrors()
            ->assertSee('Cadastro recusado. A pessoa continua sem acesso.');

        $pending->refresh();
        $this->assertSame(UserApprovalStatus::Rejected, $pending->approval_status);
        $this->assertSame($admin->id, $pending->approval_reviewed_by);
        $event = $pending->auditEvents()->where('event', UserAuditEvent::EVENT_REGISTRATION_REJECTED)->sole();
        $this->assertSame(['reason' => 'Fora do público do piloto'], $event->details);
        Notification::assertNothingSent();

        Livewire::actingAs($admin)->test(Show::class, ['user' => $pending])
            ->assertSee('Recusado por Rita Admin')
            ->call('approve')
            ->assertHasNoErrors();
        $this->assertTrue($pending->fresh()->isApproved());
        Notification::assertSentTo($pending, AccountApprovedNotification::class);
    }

    public function test_only_pending_registrations_can_be_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $approved = User::factory()->create();

        Livewire::actingAs($admin)->test(Index::class)->call('reject', $approved->id)->assertHasErrors('approval');

        $this->assertTrue($approved->fresh()->isApproved());
    }

    public function test_approval_still_applies_when_the_email_cannot_be_sent(): void
    {
        // Transporte real da KingHost sem token: o envio é recusado antes de qualquer chamada HTTP.
        config(['mail.default' => 'kinghost', 'services.kinghost_smtp.token' => null]);
        $admin = User::factory()->admin()->create();
        $pending = User::factory()->awaitingApproval()->create();

        Livewire::actingAs($admin)->test(Show::class, ['user' => $pending])
            ->call('approve')
            ->assertHasErrors('approval')
            ->assertSee('Cadastro aprovado.');

        $this->assertTrue($pending->fresh()->isApproved());
        $this->assertFalse($pending->auditEvents()->where('event', UserAuditEvent::EVENT_APPROVAL_EMAILED)->exists());
    }

    public function test_the_list_filters_pending_and_rejected_registrations(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'Carla Ativa']);
        User::factory()->awaitingApproval()->create(['name' => 'Ana Pendente']);
        User::factory()->rejected()->create(['name' => 'Bruno Recusado']);

        Livewire::actingAs($admin)->test(Index::class)
            ->call('showAwaitingApproval')
            ->assertSet('status', 'awaiting_approval')
            ->assertSee('Ana Pendente')->assertDontSee('Bruno Recusado')->assertDontSee('Carla Ativa')
            ->set('status', 'rejected')
            ->assertSee('Bruno Recusado')->assertSee('Cadastro recusado')->assertDontSee('Ana Pendente')
            ->set('status', 'active')
            ->assertSee('Carla Ativa')->assertDontSee('Ana Pendente')->assertDontSee('Bruno Recusado');
    }

    public function test_only_admins_can_approve_or_reject(): void
    {
        $user = User::factory()->create();
        $pending = User::factory()->awaitingApproval()->create();

        Livewire::actingAs($user)->test(Index::class)->assertForbidden();
        Livewire::actingAs($user)->test(Show::class, ['user' => $pending])->assertForbidden();
        $this->actingAs($user)->get(route('admin.users.show', $pending))->assertForbidden();

        $this->assertTrue($pending->fresh()->isAwaitingApproval());
    }
}
