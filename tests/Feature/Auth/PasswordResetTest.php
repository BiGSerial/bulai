<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserAuditEvent;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_page_is_linked_from_login_and_renders_for_guests(): void
    {
        $this->get('/login')->assertOk()->assertSee('Esqueci minha senha')->assertSee(route('password.request'), false);
        $this->get('/forgot-password')->assertOk()->assertSee('Enviar link de redefinição');
        $this->actingAs(User::factory()->create())->get('/forgot-password')->assertRedirect();
    }

    public function test_reset_link_is_sent_in_portuguese_only_to_active_accounts_and_the_response_never_reveals_the_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ana@example.com']);
        $inactive = User::factory()->inactive()->create(['email' => 'inativa@example.com']);

        foreach (['ANA@example.com', 'ninguem@example.com', 'inativa@example.com'] as $email) {
            $this->from('/forgot-password')->post('/forgot-password', ['email' => $email])
                ->assertRedirectToRoute('password.request')
                ->assertSessionHas('status', __('passwords.sent'))
                ->assertSessionHasNoErrors();
        }

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
            $mail = $notification->toMail($user);

            return str_contains($mail->subject, 'Redefinição de senha') && str_contains($mail->actionUrl, '/reset-password/');
        });
        Notification::assertNotSentTo($inactive, ResetPasswordNotification::class);
        $this->assertSame([UserAuditEvent::EVENT_PASSWORD_RESET_REQUESTED], $user->auditEvents()->pluck('event')->all());
        $this->assertSame(0, $inactive->auditEvents()->count());
    }

    public function test_reset_requests_are_rate_limited_per_email_and_ip(): void
    {
        Notification::fake();
        $this->freezeTime();
        foreach (range(1, 3) as $attempt) {
            $this->post('/forgot-password', ['email' => 'ana@example.com'])->assertRedirectToRoute('password.request');
        }

        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'ana@example.com'])
            ->assertRedirectToRoute('password.request')
            ->assertSessionHasErrors('email');
    }

    public function test_valid_token_resets_the_password_clears_pending_change_revokes_sessions_and_is_audited(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->mustChangePassword()->create(['email' => 'ana@example.com', 'remember_token' => 'old-remember-token']);
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'PHPUnit', 'payload' => base64_encode(serialize([])), 'last_activity' => now()->getTimestamp()]);
        $token = Password::createToken($user);

        $this->get('/reset-password/'.$token.'?email=ana@example.com')->assertOk()->assertSee('Defina a nova senha')->assertSee('ana@example.com');

        $this->from('/reset-password/'.$token)->post('/reset-password', ['token' => $token, 'email' => 'ANA@example.com', 'password' => 'short1', 'password_confirmation' => 'short1'])
            ->assertSessionHasErrors('password');

        $this->post('/reset-password', ['token' => $token, 'email' => 'ANA@example.com', 'password' => 'my-new-test-pass-9', 'password_confirmation' => 'my-new-test-pass-9'])
            ->assertRedirectToRoute('login')
            ->assertSessionHas('status', __('passwords.reset'));

        $user->refresh();
        $this->assertTrue(Hash::check('my-new-test-pass-9', $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'ana@example.com']);
        $event = $user->auditEvents()->sole();
        $this->assertSame(UserAuditEvent::EVENT_PASSWORD_RESET, $event->event);
        $this->assertSame(['via' => UserAuditEvent::VIA_RESET], $event->details);
        $this->assertGuest();

        $this->followingRedirects()->get('/login')->assertSee(__('passwords.reset'));
        $this->post('/login', ['email' => 'ana@example.com', 'password' => 'my-new-test-pass-9'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_or_reused_token_is_rejected_in_portuguese(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com', 'password' => 'current-test-pass-1']);

        $this->from('/reset-password/x')->post('/reset-password', ['token' => 'wrong-token', 'email' => 'ana@example.com', 'password' => 'my-new-test-pass-9', 'password_confirmation' => 'my-new-test-pass-9'])
            ->assertRedirect('/reset-password/x')
            ->assertSessionHasErrors(['email' => __('passwords.token')]);

        $this->assertTrue(Hash::check('current-test-pass-1', $user->refresh()->password));
        $this->assertSame(0, $user->auditEvents()->count());
    }
}
