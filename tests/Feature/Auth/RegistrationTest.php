<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserAuditEvent;
use App\Notifications\RegistrationAwaitingApprovalNotification;
use App\UserApprovalStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Cadastro pela própria pessoa: nasce aguardando aprovação e só entra depois que um administrador aprova. */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'senha-segura-2026';

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return [
            'name' => '  Maria   da Silva ',
            'email' => 'Maria@Example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'terms' => '1',
            ...$overrides,
        ];
    }

    public function test_the_register_page_shows_the_academic_notice_and_the_privacy_terms(): void
    {
        $html = $this->get(route('register'))
            ->assertOk()
            ->assertSee('data-aviso-academico', false)
            ->assertSee('Trabalho de Conclusão de Curso (TCC)')
            ->assertSee('aprovação da equipe do projeto')
            ->assertSee('Como cuidamos dos seus dados')
            ->getContent();

        // Só nome, e-mail, senha e o aceite: nenhum campo de saúde no cadastro.
        preg_match_all('/<input\b[^>]*\bname="([^"]+)"/', $html, $fields);
        $this->assertEqualsCanonicalizing(['_token', 'name', 'email', 'password', 'password_confirmation', 'terms'], $fields[1]);
    }

    public function test_the_login_page_links_to_the_register_page(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('href="'.route('register').'"', false)->assertSee('Criar minha conta');
    }

    public function test_registering_creates_a_pending_user_without_signing_in_and_notifies_active_admins(): void
    {
        Notification::fake();
        $this->travelTo('2026-11-09 10:00:00');
        $admin = User::factory()->admin()->create();
        $inactiveAdmin = User::factory()->admin()->inactive()->create();

        $this->post(route('register.store'), $this->validPayload())
            ->assertRedirectToRoute('register')
            ->assertSessionHas('registered', 'Maria da Silva');

        $this->assertGuest();
        $user = User::query()->where('email', 'maria@example.com')->sole();
        $this->assertSame(UserApprovalStatus::Pending, $user->approval_status);
        $this->assertSame(UserRole::User, $user->role);
        $this->assertSame('Maria da Silva', $user->name);
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->created_by);
        $this->assertTrue($user->terms_accepted_at->equalTo(now()));
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertSame([UserAuditEvent::EVENT_SELF_REGISTERED], $user->auditEvents()->pluck('event')->all());
        $this->assertDatabaseMissing('patient_profiles', ['user_id' => $user->id]);

        Notification::assertSentTo($admin, RegistrationAwaitingApprovalNotification::class, function (RegistrationAwaitingApprovalNotification $notification) use ($admin): bool {
            $html = (string) $notification->toMail($admin)->render();

            return str_contains($html, 'Maria da Silva') && str_contains($html, route('admin.users.show', $notification->registeredUser));
        });
        Notification::assertNotSentTo($inactiveAdmin, RegistrationAwaitingApprovalNotification::class);

        $this->get(route('register'))->assertSee('Cadastro recebido!')->assertDontSee('name="password"', false);
    }

    public function test_registration_validates_fields_terms_password_policy_and_duplicate_email(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        User::factory()->create(['email' => 'joao@example.com']);

        $this->from(route('register'))->post(route('register.store'), [])
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors(['name', 'email', 'password', 'terms']);

        $this->from(route('register'))->post(route('register.store'), $this->validPayload(['terms' => null, 'password' => 'curta', 'password_confirmation' => 'curta']))
            ->assertSessionHasErrors(['password', 'terms']);

        $this->from(route('register'))->post(route('register.store'), $this->validPayload(['password_confirmation' => 'my-own-test-pass-2']))
            ->assertSessionHasErrors('password');

        $this->from(route('register'))->post(route('register.store'), $this->validPayload(['email' => 'JOAO@example.com']))
            ->assertSessionHasErrors(['email' => 'Já existe um cadastro com este e-mail. Se ele é seu, entre com a sua senha ou use “Esqueci minha senha”.']);

        $this->assertSame(1, User::query()->count());
    }

    public function test_registration_is_rate_limited_per_ip(): void
    {
        Notification::fake();

        foreach (range(1, 3) as $attempt) {
            $this->post(route('register.store'), $this->validPayload(['email' => "pessoa{$attempt}@example.com"]))->assertRedirectToRoute('register');
        }

        $this->post(route('register.store'), $this->validPayload(['email' => 'pessoa4@example.com']))
            ->assertRedirectToRoute('register')
            ->assertSessionHasErrors('email');

        $this->assertSame(3, User::query()->count());
        $this->assertDatabaseMissing('users', ['email' => 'pessoa4@example.com']);
    }

    public function test_authenticated_users_cannot_open_the_register_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('register'))->assertRedirect();
    }
}
