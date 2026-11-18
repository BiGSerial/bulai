<?php

namespace Tests\Feature\Consulta;

use App\Models\Consultation\MedicationQuestion;
use App\Models\MedicationDoseEvent;
use App\Models\MobileDevice;
use App\Models\PatientAllergy;
use App\Models\PatientMedication;
use App\Models\PatientProfile;
use App\Models\User;
use App\Models\UserAuditEvent;
use App\Notifications\MedicationReminderNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\UnmatchedMedicineResolvedNotification;
use App\Services\Users\UserAccountService;
use App\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Aplicativo Android nativo (API /api/mobile/v1): conta, Início, Remédios do dia, avisos, agenda dos alarmes e revogação. */
final class AppAndroidTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 07:30', 'America/Sao_Paulo'));
        Carbon::setTestNow(Carbon::parse('2026-11-05 07:30', 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $profile */
    private function patient(array $profile = []): User
    {
        $user = User::factory()->create();
        PatientProfile::query()->create($profile + ['user_id' => $user->id, 'health_consent_at' => now(), 'timezone' => 'America/Sao_Paulo', 'reminders_enabled' => true]);

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function medication(User $user, string $name, array $attributes = []): PatientMedication
    {
        return PatientMedication::query()->create($attributes + [
            'user_id' => $user->id, 'medicine_id' => null, 'name_as_written' => $name, 'catalog_status' => PatientMedication::CATALOG_UNMATCHED,
            'dosage' => '1 comprimido', 'frequency' => '12/12h', 'dose_times' => ['08:00', '20:00'], 'started_at' => '2026-11-01', 'reminders_enabled' => true,
        ]);
    }

    private function connect(User $user): string
    {
        return MobileDevice::issueFor($user, ['installation_id' => (string) Str::uuid(), 'device_name' => 'Pixel 8'])['token'];
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$this->connect($user)];
    }

    public function test_login_issues_a_device_token_storing_only_the_hash_and_signing_in_again_rotates_it(): void
    {
        $user = $this->patient();
        $user->forceFill(['email' => 'maria@exemplo.com'])->save();
        $installation = (string) Str::uuid();
        $credentials = ['email' => ' MARIA@exemplo.com ', 'password' => 'password', 'installation_id' => $installation, 'device_name' => 'Pixel 8', 'app_version' => '1.0.0'];

        $first = $this->postJson(route('api.mobile.login'), $credentials)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('user.id', $user->id)->assertJsonPath('user.must_change_password', false)->assertJsonPath('user.health_consent', true)
            ->json('token');
        $this->assertStringStartsWith(MobileDevice::TOKEN_PREFIX, $first);
        $device = MobileDevice::query()->sole();
        $this->assertSame([MobileDevice::hashFor($first), 'Pixel 8'], [$device->token_hash, $device->device_name]);
        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertTrue(UserAuditEvent::query()->where('user_id', $user->id)->where('event', UserAuditEvent::EVENT_MOBILE_DEVICE_CONNECTED)->exists());

        $second = $this->postJson(route('api.mobile.login'), $credentials)->assertOk()->json('token');
        $this->assertSame(1, MobileDevice::query()->count());
        $this->getJson(route('api.mobile.me'), ['Authorization' => 'Bearer '.$first])->assertUnauthorized();
        $this->getJson(route('api.mobile.me'), ['Authorization' => 'Bearer '.$second])->assertOk()->assertJsonPath('user.first_name', explode(' ', $user->name)[0]);
    }

    public function test_login_refuses_wrong_passwords_and_only_reveals_blocked_accounts_to_who_knows_the_password(): void
    {
        $installation = (string) Str::uuid();
        $pending = User::factory()->awaitingApproval()->create(['email' => 'pendente@exemplo.com']);

        $this->postJson(route('api.mobile.login'), ['email' => 'pendente@exemplo.com', 'password' => 'errada', 'installation_id' => $installation])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', __('auth.failed'));
        $this->postJson(route('api.mobile.login'), ['email' => 'pendente@exemplo.com', 'password' => 'password', 'installation_id' => $installation])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', $pending->signInBlockedMessage());
        $this->postJson(route('api.mobile.login'), ['email' => 'pendente@exemplo.com', 'password' => 'password', 'installation_id' => 'nao-e-uuid'])
            ->assertUnprocessable()->assertJsonValidationErrors('installation_id');
        $this->assertSame(0, MobileDevice::query()->count());
    }

    public function test_login_is_throttled_with_a_json_message(): void
    {
        $payload = ['email' => 'alguem@exemplo.com', 'password' => 'errada', 'installation_id' => (string) Str::uuid()];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('api.mobile.login'), $payload)->assertUnprocessable();
        }

        $this->postJson(route('api.mobile.login'), $payload)->assertStatus(429)->assertJsonStructure(['message']);
    }

    public function test_register_creates_an_account_awaiting_approval_without_a_token(): void
    {
        $this->postJson(route('api.mobile.register'), ['name' => 'Joana Silva', 'email' => 'joana@exemplo.com', 'password' => 'senha-forte-123', 'password_confirmation' => 'senha-forte-123'])
            ->assertUnprocessable()->assertJsonValidationErrors('terms');

        $this->postJson(route('api.mobile.register'), ['name' => 'Joana Silva', 'email' => 'joana@exemplo.com', 'password' => 'senha-forte-123', 'password_confirmation' => 'senha-forte-123', 'terms' => true])
            ->assertCreated()->assertJsonMissingPath('token')->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Joana'));
        $this->assertTrue(User::query()->where('email', 'joana@exemplo.com')->sole()->isAwaitingApproval());
    }

    public function test_forgot_password_answers_the_same_whether_the_email_exists_or_not(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ana@exemplo.com']);

        $known = $this->postJson(route('api.mobile.password.forgot'), ['email' => 'ana@exemplo.com'])->assertOk()->json('message');
        $unknown = $this->postJson(route('api.mobile.password.forgot'), ['email' => 'ninguem@exemplo.com'])->assertOk()->json('message');

        $this->assertSame($known, $unknown);
        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_a_temporary_password_only_allows_the_first_access_and_changing_it_keeps_this_device(): void
    {
        $user = User::factory()->mustChangePassword()->create();
        $headers = $this->bearer($user);
        $otherDevice = $this->connect($user);

        $this->getJson(route('api.mobile.home'), $headers)->assertForbidden()->assertJsonPath('code', 'must_change_password');
        $this->getJson(route('api.mobile.me'), $headers)->assertOk()->assertJsonPath('user.must_change_password', true);

        $this->postJson(route('api.mobile.first-access'), ['password' => 'password', 'password_confirmation' => 'password'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson(route('api.mobile.first-access'), ['password' => 'nova-senha-123', 'password_confirmation' => 'nova-senha-123'], $headers)
            ->assertOk()->assertJsonPath('user.must_change_password', false);

        $this->getJson(route('api.mobile.home'), $headers)->assertOk();
        $this->getJson(route('api.mobile.me'), ['Authorization' => 'Bearer '.$otherDevice])->assertUnauthorized();
    }

    public function test_agenda_lists_today_and_tomorrow_with_what_should_ring_and_what_is_already_marked(): void
    {
        $user = $this->patient();
        $losartana = $this->medication($user, 'Losartana');
        $this->medication($user, 'Dipirona', ['dose_times' => ['10:00'], 'reminders_enabled' => false]);
        $this->medication($this->patient(), 'Privado ZZ');
        MedicationDoseEvent::query()->create(['user_id' => $user->id, 'patient_medication_id' => $losartana->id, 'scheduled_for' => CarbonImmutable::parse('2026-11-05 08:00', 'America/Sao_Paulo')->setTimezone(config('app.timezone')), 'status' => MedicationDoseEvent::STATUS_TAKEN, 'recorded_at' => now()]);

        $response = $this->getJson(route('api.mobile.agenda'), ['Authorization' => 'Bearer '.$this->connect($user)])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('timezone', 'America/Sao_Paulo')->assertJsonPath('reminders_enabled', true)->assertJsonCount(6, 'doses')
            ->assertJsonMissing(['name' => 'Privado ZZ']);

        $doses = collect($response->json('doses'));
        $first = $doses->firstWhere('scheduled_for', '2026-11-05T08:00:00-03:00');
        $this->assertSame(['Losartana', 'taken', true], [$first['name'], $first['status'], $first['remind']]);
        $this->assertSame($losartana->id.'|2026-11-05T11:00:00+00:00', $first['key']);
        $this->assertFalse($doses->firstWhere('name', 'Dipirona')['remind']);
        $this->assertTrue($doses->contains('scheduled_for', '2026-11-06T20:00:00-03:00'));
    }

    public function test_without_consent_or_with_reminders_off_nothing_rings(): void
    {
        $semConsentimento = $this->patient(['health_consent_at' => null]);
        $this->medication($semConsentimento, 'Losartana');
        $desligado = $this->patient(['reminders_enabled' => false]);
        $this->medication($desligado, 'Losartana');

        $this->getJson(route('api.mobile.agenda'), ['Authorization' => 'Bearer '.$this->connect($semConsentimento)])->assertOk()
            ->assertJsonPath('reminders_enabled', false)->assertJsonPath('reminders_blocked_reason', 'consent')->assertJsonPath('doses.0.remind', false);
        $this->getJson(route('api.mobile.agenda'), ['Authorization' => 'Bearer '.$this->connect($desligado)])->assertOk()
            ->assertJsonPath('reminders_blocked_reason', 'disabled')->assertJsonPath('doses.0.remind', false);
    }

    public function test_the_notification_marks_a_dose_and_returns_the_fresh_agenda(): void
    {
        $user = $this->patient();
        $losartana = $this->medication($user, 'Losartana');
        $alheio = $this->medication($this->patient(), 'Privado ZZ');
        $headers = ['Authorization' => 'Bearer '.$this->connect($user)];

        $this->postJson(route('api.mobile.doses.mark'), ['medication_id' => $losartana->id, 'scheduled_for' => '2026-11-05T11:00:00+00:00', 'status' => 'taken', 'recorded_at' => '2026-11-05T10:35:00+00:00'], $headers)
            ->assertOk()->assertJsonPath('doses.0.status', 'taken');
        $event = MedicationDoseEvent::query()->sole();
        $this->assertSame([MedicationDoseEvent::STATUS_TAKEN, '2026-11-05T11:00:00+00:00', '2026-11-05T10:35:00+00:00'], [$event->status, $event->scheduled_for->utc()->toIso8601String(), $event->recorded_at->utc()->toIso8601String()]);

        $this->postJson(route('api.mobile.doses.mark'), ['medication_id' => $losartana->id, 'scheduled_for' => '2026-11-05T11:00:00+00:00', 'status' => 'skipped'], $headers)->assertOk();
        $this->assertSame(MedicationDoseEvent::STATUS_SKIPPED, MedicationDoseEvent::query()->sole()->status);

        $this->postJson(route('api.mobile.doses.mark'), ['medication_id' => $alheio->id, 'scheduled_for' => '2026-11-05T11:00:00+00:00', 'status' => 'taken'], $headers)->assertNotFound();
        $this->postJson(route('api.mobile.doses.mark'), ['medication_id' => $losartana->id, 'scheduled_for' => '2026-11-20T11:00:00+00:00', 'status' => 'taken'], $headers)->assertUnprocessable()->assertJsonValidationErrors('scheduled_for');
        $this->postJson(route('api.mobile.doses.mark'), ['medication_id' => $losartana->id, 'scheduled_for' => '2026-11-05T11:00:00+00:00', 'status' => 'taken', 'recorded_at' => '2026-11-05T15:00:00+00:00'], $headers)->assertUnprocessable()->assertJsonValidationErrors('recorded_at');
        $this->postJson(route('api.mobile.doses.mark'), ['medication_id' => $losartana->id, 'scheduled_for' => '2026-11-05T11:00:00+00:00', 'status' => 'talvez'], $headers)->assertUnprocessable();
        $this->assertSame(1, MedicationDoseEvent::query()->count());
    }

    public function test_notices_bring_only_the_patients_unread_ones_after_the_last_check_with_the_screen_to_open(): void
    {
        $user = $this->patient();
        $medication = $this->medication($user, 'Losartana');
        $user->notify(new MedicationReminderNotification($medication, CarbonImmutable::parse('2026-11-05 08:00', 'America/Sao_Paulo')));
        $user->notify(new UnmatchedMedicineResolvedNotification($medication));
        $other = $this->patient();
        $other->notify(new UnmatchedMedicineResolvedNotification($this->medication($other, 'Privado ZZ')));
        $headers = ['Authorization' => 'Bearer '.$this->connect($user)];

        $notices = $this->getJson(route('api.mobile.notices.fresh'), $headers)->assertOk()->assertJsonCount(2, 'notices')->json('notices');
        $this->assertEqualsCanonicalizing(['dose', 'medicine_resolved'], array_column($notices, 'kind'));
        $this->assertSame(['/hoje', 'consulta.hoje'], [collect($notices)->firstWhere('kind', 'dose')['url'], collect($notices)->firstWhere('kind', 'dose')['destination']['route']]);
        $this->assertStringContainsString('"params":{}', $this->getJson(route('api.mobile.notices.fresh'), $headers)->getContent());

        $this->getJson(route('api.mobile.notices.fresh', ['desde' => now()->addMinute()->toIso8601String()]), $headers)->assertOk()->assertJsonCount(0, 'notices');
    }

    public function test_invalid_tokens_blocked_accounts_and_lost_access_disconnect_the_app(): void
    {
        $this->getJson(route('api.mobile.agenda'))->assertUnauthorized();
        $this->getJson(route('api.mobile.agenda'), ['Authorization' => 'Bearer bulai_app_inexistente'])->assertUnauthorized();

        $inactive = User::factory()->inactive()->create();
        $this->getJson(route('api.mobile.agenda'), ['Authorization' => 'Bearer '.$this->connect($inactive)])->assertUnauthorized();

        $user = $this->patient();
        $token = $this->connect($user);
        app(UserAccountService::class)->terminateSessions($user, $user, 'sessao-atual');
        $this->assertSame(0, $user->mobileDevices()->count());
        $this->getJson(route('api.mobile.agenda'), ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_the_app_disconnects_itself(): void
    {
        $user = $this->patient();
        $token = $this->connect($user);

        $this->deleteJson(route('api.mobile.device.destroy'), [], ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->assertSame(0, MobileDevice::query()->count());
        $this->assertTrue(UserAuditEvent::query()->where('user_id', $user->id)->where('event', UserAuditEvent::EVENT_MOBILE_DEVICE_DISCONNECTED)->exists());
        $this->getJson(route('api.mobile.agenda'), ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_home_shows_the_day_card_with_the_next_dose_and_who_the_patient_cares_for(): void
    {
        $user = $this->patient();
        $this->medication($user, 'Losartana');
        $user->notify(new UnmatchedMedicineResolvedNotification($this->medication($user, 'Dipirona', ['dose_times' => ['22:00']])));

        $this->getJson(route('api.mobile.home'), $this->bearer($user))->assertOk()
            ->assertJsonPath('health_consent', true)->assertJsonPath('has_medications', true)
            ->assertJsonPath('next_dose.name', 'Losartana')->assertJsonPath('next_dose.time', '08:00')->assertJsonPath('next_dose.status', 'pending')->assertJsonPath('next_dose.is_today', true)
            ->assertJsonPath('progress.total', 3)->assertJsonPath('progress.done', 0)->assertJsonPath('unread_notices', 1)
            ->assertJsonPath('date_label', 'Quinta-feira, 5 de novembro')
            ->assertJsonPath('caring_for', []);

        $semConsentimento = $this->patient(['health_consent_at' => null]);
        $this->getJson(route('api.mobile.home'), $this->bearer($semConsentimento))->assertOk()
            ->assertJsonPath('health_consent', false)->assertJsonPath('next_dose', null)->assertJsonPath('progress.total', 0);
    }

    public function test_the_day_screen_lists_yesterday_today_or_tomorrow_and_marks_and_unmarks_doses(): void
    {
        $user = $this->patient();
        $losartana = $this->medication($user, 'Losartana');
        $this->medication($user, 'Dipirona SOS', ['dose_times' => [], 'as_needed' => true]);
        $alheio = $this->medication($this->patient(), 'Privado ZZ');
        $headers = $this->bearer($user);

        $this->getJson(route('api.mobile.day'), $headers)->assertOk()
            ->assertJsonPath('offset', 0)->assertJsonPath('date', '2026-11-05')->assertJsonCount(2, 'doses')
            ->assertJsonPath('doses.0.period', 'manha')->assertJsonPath('doses.1.period', 'noite')
            ->assertJsonPath('as_needed.0.name', 'Dipirona SOS')->assertJsonMissing(['name' => 'Privado ZZ']);
        $this->getJson(route('api.mobile.day', ['dia' => -1]), $headers)->assertOk()->assertJsonPath('date', '2026-11-04');
        $this->getJson(route('api.mobile.day', ['dia' => 5]), $headers)->assertUnprocessable();

        $dose = ['medication_id' => $losartana->id, 'scheduled_for' => '2026-11-05T08:00:00-03:00'];
        $this->postJson(route('api.mobile.day.mark'), $dose + ['status' => 'taken'], $headers)->assertOk()
            ->assertJsonPath('doses.0.status', 'taken')->assertJsonPath('summary.taken', 1)->assertJsonPath('summary.done', 1);
        $this->deleteJson(route('api.mobile.day.unmark'), $dose, $headers)->assertOk()->assertJsonPath('doses.0.status', 'pending');
        $this->assertSame(0, MedicationDoseEvent::query()->count());

        $this->postJson(route('api.mobile.day.mark'), ['medication_id' => $alheio->id, 'scheduled_for' => '2026-11-05T08:00:00-03:00', 'status' => 'taken'], $headers)->assertNotFound();
    }

    public function test_the_notice_center_opens_reads_deletes_and_saves_reminder_preferences(): void
    {
        $user = $this->patient();
        $medication = $this->medication($user, 'Losartana');
        $user->notify(new UnmatchedMedicineResolvedNotification($medication));
        $user->notify(new MedicationReminderNotification($medication, CarbonImmutable::parse('2026-11-05 08:00', 'America/Sao_Paulo')));
        $headers = $this->bearer($user);

        $list = $this->getJson(route('api.mobile.notices'), $headers)->assertOk()
            ->assertJsonPath('unread', 2)->assertJsonCount(2, 'notices')->assertJsonPath('preferences.reminders', true)->assertJsonPath('preferences.timezone', 'America/Sao_Paulo')
            ->assertJsonPath('timezones.0.id', 'America/Sao_Paulo')->json('notices');
        $resolved = collect($list)->firstWhere('kind', 'medicine_resolved');
        $this->assertSame(['Ver meus remédios', false], [$resolved['action'], $resolved['read']]);

        $this->postJson(route('api.mobile.notices.open', $resolved['id']), [], $headers)->assertOk()->assertJsonPath('destination.route', 'consulta.perfil');
        $this->getJson(route('api.mobile.notices'), $headers)->assertJsonPath('unread', 1);
        $this->postJson(route('api.mobile.notices.read-all'), [], $headers)->assertOk()->assertJsonPath('unread', 0);
        $this->deleteJson(route('api.mobile.notices.destroy', $resolved['id']), [], $headers)->assertOk()->assertJsonCount(1, 'notices');
        $this->deleteJson(route('api.mobile.notices.destroy-all'), [], $headers)->assertOk()->assertJsonCount(0, 'notices');

        $this->putJson(route('api.mobile.notices.preferences'), ['reminders' => false, 'email' => true, 'timezone' => 'America/Manaus'], $headers)->assertOk()
            ->assertJsonPath('preferences.reminders', false)->assertJsonPath('preferences.email', true)->assertJsonPath('preferences.timezone', 'America/Manaus');
        $this->putJson(route('api.mobile.notices.preferences'), ['timezone' => 'Europe/Lisbon'], $headers)->assertUnprocessable();

        $other = $this->patient();
        $other->notify(new UnmatchedMedicineResolvedNotification($this->medication($other, 'Privado ZZ')));
        $this->postJson(route('api.mobile.notices.open', $other->notifications()->first()->id), [], $headers)->assertNotFound();
    }

    public function test_the_person_sets_how_to_be_called_and_the_greeting_and_the_site_follow(): void
    {
        $user = $this->patient();
        $user->forceFill(['name' => 'Maria Aparecida da Silva'])->save();
        $headers = $this->bearer($user);

        $this->putJson(route('api.mobile.account.name'), ['preferred_name' => '  Cida  '], $headers)->assertOk()
            ->assertJsonPath('user.preferred_name', 'Cida')->assertJsonPath('user.first_name', 'Cida');
        $this->getJson(route('api.mobile.home'), $headers)->assertJsonPath('first_name', 'Cida');
        $this->actingAs($user->refresh())->get(route('consulta.inicio'))->assertSee('Olá, Cida');
        $this->assertEquals(['from' => null, 'to' => 'Cida'], UserAuditEvent::query()->where('event', UserAuditEvent::EVENT_PREFERRED_NAME_CHANGED)->sole()->details);

        $this->putJson(route('api.mobile.account.name'), ['preferred_name' => '<script>'], $headers)->assertUnprocessable()->assertJsonValidationErrors('preferred_name');
        $this->putJson(route('api.mobile.account.name'), ['preferred_name' => ''], $headers)->assertOk()->assertJsonPath('user.first_name', 'Maria');
    }

    public function test_changing_the_password_asks_the_current_one_and_keeps_only_this_device(): void
    {
        $user = $this->patient();
        $headers = $this->bearer($user);
        $other = $this->bearer($user);

        $this->putJson(route('api.mobile.account.password'), ['current_password' => 'errada', 'password' => 'Nova-senha-2026', 'password_confirmation' => 'Nova-senha-2026'], $headers)
            ->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'A senha atual não confere.');
        $this->putJson(route('api.mobile.account.password'), ['current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->putJson(route('api.mobile.account.password'), ['current_password' => 'password', 'password' => 'Nova-senha-2026', 'password_confirmation' => 'Nova-senha-2026'], $headers)->assertOk();
        $this->assertTrue(Hash::check('Nova-senha-2026', $user->refresh()->password));
        $this->getJson(route('api.mobile.account'), $headers)->assertOk();
        $this->getJson(route('api.mobile.account'), $other)->assertUnauthorized();
    }

    public function test_deleting_the_account_asks_the_password_erases_personal_and_health_data_and_anonymizes_the_user(): void
    {
        $user = $this->patient();
        $user->forceFill(['email' => 'maria@exemplo.com', 'preferred_name' => 'Mari', 'last_login_ip' => '10.0.0.1'])->save();
        $headers = $this->bearer($user);
        $losartana = $this->medication($user, 'Losartana 50 mg');
        PatientAllergy::query()->create(['user_id' => $user->id, 'substance' => 'Dipirona', 'normalized_substance' => 'dipirona']);
        MedicationQuestion::query()->create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'medicine_name' => '—', 'question' => 'posso?', 'status' => 'answered', 'expires_at' => now()->addDay()]);
        $user->notify(new UnmatchedMedicineResolvedNotification($losartana));
        $other = $this->patient();
        $this->medication($other, 'Omeprazol');

        $this->deleteJson(route('api.mobile.account.destroy'), ['password' => 'errada'], $headers)->assertUnprocessable()->assertJsonPath('errors.password.0', 'A senha não confere.');
        $this->assertSame(1, PatientMedication::query()->where('user_id', $user->id)->count());

        $this->deleteJson(route('api.mobile.account.destroy'), ['password' => 'password'], $headers)->assertOk();

        $user->refresh();
        $this->assertSame(['Conta apagada', null, null, false], [$user->name, $user->preferred_name, $user->last_login_ip, $user->active]);
        $this->assertStringEndsWith('@contas-apagadas.invalid', $user->email);
        foreach ([PatientMedication::class, PatientAllergy::class, MedicationQuestion::class, PatientProfile::class, MobileDevice::class] as $model) {
            $this->assertSame(0, $model::query()->where('user_id', $user->id)->count(), $model);
        }
        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame(1, PatientMedication::query()->where('user_id', $other->id)->count(), 'os dados de outra pessoa ficam');
        $this->assertSame(1, UserAuditEvent::query()->where('user_id', $user->id)->where('event', UserAuditEvent::EVENT_ACCOUNT_DELETED)->count());
        $this->getJson(route('api.mobile.account'), $headers)->assertUnauthorized();

        // O e-mail fica livre para um novo cadastro.
        $this->postJson(route('api.mobile.register'), ['name' => 'Maria Nova', 'email' => 'maria@exemplo.com', 'password' => 'Senha-forte-2026', 'password_confirmation' => 'Senha-forte-2026', 'terms' => true])->assertCreated();
    }

    public function test_an_administrator_account_cannot_be_deleted_from_the_app(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->deleteJson(route('api.mobile.account.destroy'), ['password' => 'password'], $this->bearer($admin))->assertForbidden()->assertJsonPath('code', 'admin_account');
        $this->assertTrue($admin->refresh()->active);
    }
}
