<?php

namespace Tests\Feature\Consulta;

use App\Models\MedicationDoseEvent;
use App\Models\MobileDevice;
use App\Models\PatientMedication;
use App\Models\PatientProfile;
use App\Models\User;
use App\Models\UserAuditEvent;
use App\Notifications\MedicationReminderNotification;
use App\Notifications\UnmatchedMedicineResolvedNotification;
use App\Services\Users\UserAccountService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Aplicativo Android: ligar o aparelho à conta, agenda dos alarmes, marcação pela notificação, avisos e revogação. */
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

    public function test_the_page_connects_the_app_storing_only_the_hash_and_reconnecting_rotates_the_token(): void
    {
        $user = $this->patient();
        $installation = (string) Str::uuid();

        $this->postJson(route('mobile-device.store'), ['installation_id' => $installation])->assertUnauthorized();

        $first = $this->actingAs($user)->postJson(route('mobile-device.store'), ['installation_id' => $installation, 'device_name' => 'Pixel 8', 'app_version' => '1.0.0'])
            ->assertOk()->assertJsonPath('user_id', $user->id)->json('token');
        $this->assertStringStartsWith(MobileDevice::TOKEN_PREFIX, $first);
        $device = MobileDevice::query()->sole();
        $this->assertSame(MobileDevice::hashFor($first), $device->token_hash);
        $this->assertSame('Pixel 8', $device->device_name);
        $this->assertTrue(UserAuditEvent::query()->where('user_id', $user->id)->where('event', UserAuditEvent::EVENT_MOBILE_DEVICE_CONNECTED)->exists());

        $second = $this->actingAs($user)->postJson(route('mobile-device.store'), ['installation_id' => $installation])->assertOk()->json('token');
        $this->assertSame(1, MobileDevice::query()->count());
        $this->getJson(route('api.mobile.agenda'), ['Authorization' => 'Bearer '.$first])->assertUnauthorized();
        $this->getJson(route('api.mobile.agenda'), ['Authorization' => 'Bearer '.$second])->assertOk();

        $this->actingAs($user)->postJson(route('mobile-device.store'), ['installation_id' => 'nao-e-uuid'])->assertUnprocessable();
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

        $notices = $this->getJson(route('api.mobile.notices'), $headers)->assertOk()->assertJsonCount(2, 'notices')->json('notices');
        $this->assertEqualsCanonicalizing(['dose', 'medicine_resolved'], array_column($notices, 'kind'));
        $this->assertSame('/hoje', collect($notices)->firstWhere('kind', 'dose')['url']);

        $this->getJson(route('api.mobile.notices', ['desde' => now()->addMinute()->toIso8601String()]), $headers)->assertOk()->assertJsonCount(0, 'notices');
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

    public function test_pages_tell_the_app_who_is_signed_in_and_the_notices_screen_has_the_alarm_settings(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('<meta name="bulai-sessao" content="">', false);
        $user = $this->patient();
        $this->medication($user, 'Losartana');

        $this->actingAs($user)->get(route('consulta.avisos'))->assertOk()
            ->assertSee('<meta name="bulai-sessao" content="'.$user->id.'">', false)
            ->assertSee('Alarmes neste celular')->assertSee('Tocar como despertador')->assertSee('Testar o alarme agora');
    }
}
