<?php

namespace Tests\Feature\Consulta;

use App\Livewire\Consulta\Avisos;
use App\Livewire\Consulta\SinoDeAvisos;
use App\Models\MedicationDoseEvent;
use App\Models\PatientMedication;
use App\Models\PatientProfile;
use App\Models\User;
use App\Notifications\MedicationReminderNotification;
use App\Notifications\TreatmentEndingNotification;
use App\Notifications\UnmatchedMedicineResolvedNotification;
use App\Services\Treatment\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Lembretes de dose, aviso de fim de tratamento, deduplicação, tela de avisos, preferências e sino. */
final class AvisosTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $profile */
    private function patient(array $profile = []): User
    {
        $user = User::factory()->create();
        PatientProfile::query()->create(['user_id' => $user->id, 'health_consent_at' => now(), 'timezone' => 'America/Sao_Paulo'] + $profile);

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function medication(User $user, string $name, array $attributes = []): PatientMedication
    {
        return PatientMedication::query()->create($attributes + [
            'user_id' => $user->id, 'medicine_id' => null, 'name_as_written' => $name, 'catalog_status' => PatientMedication::CATALOG_UNMATCHED,
            'dosage' => '1 comprimido', 'frequency' => '12/12h', 'dose_times' => ['08:00', '20:00'], 'started_at' => '2026-11-01',
        ]);
    }

    public function test_reminders_go_only_to_due_unmarked_doses_of_patients_who_want_them(): void
    {
        Notification::fake();
        $user = $this->patient();
        $losartana = $this->medication($user, 'Losartana');
        $this->medication($user, 'Sem lembrete', ['reminders_enabled' => false]);
        $this->medication($user, 'Se precisar', ['as_needed' => true, 'frequency' => 'se precisar']);
        $marcado = $this->medication($user, 'Já tomei', ['dose_times' => ['08:00']]);
        MedicationDoseEvent::query()->create(['user_id' => $user->id, 'patient_medication_id' => $marcado->id, 'scheduled_for' => CarbonImmutable::parse('2026-11-05 08:00', 'America/Sao_Paulo')->setTimezone(config('app.timezone')), 'status' => MedicationDoseEvent::STATUS_TAKEN, 'recorded_at' => now()]);
        $desligado = $this->patient(['reminders_enabled' => false]);
        $this->medication($desligado, 'Losartana');
        $semConsentimento = User::factory()->create();
        PatientProfile::query()->create(['user_id' => $semConsentimento->id, 'health_consent_at' => null]);
        $this->medication($semConsentimento, 'Losartana');

        $now = CarbonImmutable::parse('2026-11-05 07:55', 'America/Sao_Paulo');
        $totals = app(ReminderService::class)->run($now);

        $this->assertSame(['patients' => 1, 'doses' => 1, 'endings' => 0], $totals);
        Notification::assertSentTo($user, MedicationReminderNotification::class, function (MedicationReminderNotification $notification, array $channels) use ($user, $losartana): bool {
            $data = $notification->toDatabase($user);

            return $channels === ['database']
                && $data['medication_id'] === $losartana->id
                && $data['time'] === '08:00'
                && $notification->dedupeKey() === 'dose:'.$losartana->id.':2026-11-05T11:00:00+00:00'
                && str_contains($data['title'], 'Losartana');
        });
        Notification::assertCount(1);
        Notification::assertNotSentTo([$desligado, $semConsentimento], MedicationReminderNotification::class);

        // Fora da janela (antes do lead ou depois do atraso) nada é enviado.
        app(ReminderService::class)->run(CarbonImmutable::parse('2026-11-05 07:40', 'America/Sao_Paulo'));
        app(ReminderService::class)->run(CarbonImmutable::parse('2026-11-05 09:05', 'America/Sao_Paulo'));
        Notification::assertCount(1);
    }

    public function test_email_channel_is_added_only_when_the_patient_asked_for_it(): void
    {
        Notification::fake();
        $user = $this->patient(['reminders_email' => true]);
        $this->medication($user, 'Losartana');

        app(ReminderService::class)->run(CarbonImmutable::parse('2026-11-05 08:30', 'America/Sao_Paulo'));

        Notification::assertSentTo($user, MedicationReminderNotification::class, fn (MedicationReminderNotification $notification, array $channels): bool => $channels === ['database', 'mail']
            && str_contains($notification->toMail($user)->subject, 'Losartana às 08:00'));
    }

    public function test_reminders_are_written_once_per_dose_even_when_the_command_runs_again(): void
    {
        $user = $this->patient();
        $medication = $this->medication($user, 'Losartana');

        $this->artisan('treatment:send-reminders', ['--now' => '2026-11-05T10:55:00+00:00'])->expectsOutputToContain('1 lembrete(s) de dose')->assertSuccessful();
        $this->artisan('treatment:send-reminders', ['--now' => '2026-11-05T11:05:00+00:00'])->expectsOutputToContain('0 lembrete(s) de dose')->assertSuccessful();

        $rows = DatabaseNotification::query()->where('notifiable_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['dose:'.$medication->id.':2026-11-05T11:00:00+00:00', 'Hora do remédio: Losartana', 'consulta.hoje'], [$rows[0]->dedupe_key, $rows[0]->data['title'], $rows[0]->data['route']]);

        // O canal ignora uma segunda gravação com a mesma chave sem lançar exceção.
        $user->notify(new MedicationReminderNotification($medication, CarbonImmutable::parse('2026-11-05 08:00', 'America/Sao_Paulo')));
        $this->assertSame(1, DatabaseNotification::query()->count());

        // Avisos sem chave continuam livres.
        $user->notify(new UnmatchedMedicineResolvedNotification($medication));
        $this->assertNull(DatabaseNotification::query()->where('type', UnmatchedMedicineResolvedNotification::class)->sole()->dedupe_key);
    }

    public function test_treatment_ending_notice_is_sent_once_from_eight_in_the_morning(): void
    {
        $user = $this->patient();
        $medication = $this->medication($user, 'Amoxicilina', ['dose_times' => ['14:00'], 'ends_at' => '2026-11-05']);
        $this->medication($user, 'Contínuo', ['dose_times' => ['14:00']]);

        app(ReminderService::class)->run(CarbonImmutable::parse('2026-11-05 07:30', 'America/Sao_Paulo'));
        $this->assertSame(0, DatabaseNotification::query()->count());

        $totals = app(ReminderService::class)->run(CarbonImmutable::parse('2026-11-05 08:10', 'America/Sao_Paulo'));
        app(ReminderService::class)->run(CarbonImmutable::parse('2026-11-05 12:00', 'America/Sao_Paulo'));

        $this->assertSame(1, $totals['endings']);
        $this->assertSame([[TreatmentEndingNotification::class, 'end:'.$medication->id.':2026-11-05']], DatabaseNotification::query()->get()->map(fn ($row): array => [$row->type, $row->dedupe_key])->all());
        $row = DatabaseNotification::query()->sole();
        $this->assertSame([TreatmentEndingNotification::class, 'end:'.$medication->id.':2026-11-05', 'consulta.perfil'], [$row->type, $row->dedupe_key, $row->data['route']]);
        $this->assertStringContainsString('Amoxicilina', $row->data['title']);
    }

    public function test_the_reminder_command_is_scheduled_every_five_minutes_on_one_server(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains($event->command, 'treatment:send-reminders'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_notices_page_lists_marks_opens_and_deletes_only_the_patients_own_notices(): void
    {
        $user = $this->patient();
        $other = $this->patient();
        $medication = $this->medication($user, 'Losartana');
        $user->notify(new MedicationReminderNotification($medication, CarbonImmutable::parse('2026-11-05 08:00', 'America/Sao_Paulo')));
        $user->notify(new UnmatchedMedicineResolvedNotification($medication));
        $other->notify(new UnmatchedMedicineResolvedNotification($this->medication($other, 'Privado ZZ')));

        $this->actingAs($user)->get(route('consulta.avisos'))->assertOk()->assertSee('Hora do remédio: Losartana')->assertSee('Novo')->assertDontSee('Privado ZZ')->assertSee('2 avisos novos')->assertSee('Marcar todos como lidos');

        $component = Livewire::actingAs($user)->test(Avisos::class)->assertSee('Ver remédios de hoje')->assertSee('Ver meus remédios');
        $dose = $user->notifications()->where('type', MedicationReminderNotification::class)->sole();
        $component->call('abrir', $dose->id)->assertRedirect(route('consulta.hoje'));
        $this->assertNotNull($dose->refresh()->read_at);

        $component->call('marcarTodosLidos')->assertSee('marcados como lidos');
        $this->assertSame(0, $user->unreadNotifications()->count());

        $component->call('apagar', $dose->id);
        $this->assertSame(1, $user->notifications()->count());

        // Avisos de outra pessoa não podem ser abertos nem apagados.
        $alheio = $other->notifications()->sole();
        Livewire::actingAs($user)->test(Avisos::class)->call('abrir', $alheio->id)->assertNoRedirect()->call('apagar', $alheio->id);
        $this->assertSame(1, $other->notifications()->count());
    }

    public function test_clearing_all_notices_deletes_only_the_patients_own_notices(): void
    {
        $user = $this->patient();
        $other = $this->patient();
        $medication = $this->medication($user, 'Losartana');
        $user->notify(new MedicationReminderNotification($medication, CarbonImmutable::parse('2026-11-05 08:00', 'America/Sao_Paulo')));
        $user->notify(new UnmatchedMedicineResolvedNotification($medication));
        $other->notify(new UnmatchedMedicineResolvedNotification($this->medication($other, 'Privado ZZ')));

        Livewire::actingAs($user)->test(Avisos::class)->assertSee('Limpar todos')->call('limparTodos')
            ->assertSee('Todos os avisos foram apagados.')->assertSee('Nenhum aviso por enquanto.')->assertDontSee('Limpar todos');

        $this->assertSame([0, 1], [$user->notifications()->count(), $other->notifications()->count()]);
    }

    public function test_reminder_preferences_are_saved_only_with_consent_and_reject_unknown_timezones(): void
    {
        $user = $this->patient();

        Livewire::actingAs($user)->test(Avisos::class)->assertSet('lembretes', true)->assertSet('fuso', 'America/Sao_Paulo')
            ->set('lembretes', false)->assertSee('Lembretes desligados')
            ->set('email', true)->assertSee('por e-mail')
            ->set('fuso', 'America/Manaus')->assertSee('Manaus')
            ->set('fuso', 'Europe/Lisbon')->assertSet('fuso', 'America/Sao_Paulo');
        $profile = PatientProfile::query()->where('user_id', $user->id)->sole();
        $this->assertSame([false, true, 'America/Manaus'], [$profile->reminders_enabled, $profile->reminders_email, $profile->timezone]);

        $semConsentimento = User::factory()->create();
        Livewire::actingAs($semConsentimento)->test(Avisos::class)->assertSee('aceite guardar seus dados de saúde')->assertDontSee('Receber lembretes de horário')
            ->set('lembretes', false);
        $this->assertSame(0, PatientProfile::query()->where('user_id', $semConsentimento->id)->count());
    }

    public function test_bell_counts_unread_notices_and_announces_new_ones_to_the_browser(): void
    {
        $user = $this->patient();
        $medication = $this->medication($user, 'Losartana');

        $component = Livewire::actingAs($user)->test(SinoDeAvisos::class)->assertSeeHtml('aria-label="Avisos, 0 não lidos"')->assertSeeHtml('wire:poll.60s="verificar"');
        $component->call('verificar')->assertNotDispatched('bulai-aviso');

        $this->travel(2)->seconds();
        $user->notify(new MedicationReminderNotification($medication, CarbonImmutable::parse('2026-11-05 08:00', 'America/Sao_Paulo')));
        $component->call('verificar')->assertDispatched('bulai-aviso', title: 'Hora do remédio: Losartana')->assertSeeHtml('aria-label="Avisos, 1 não lido"');
        // O mesmo aviso não é anunciado duas vezes.
        $component->call('verificar')->assertNotDispatched('bulai-aviso');

        $this->actingAs($user)->get(route('consulta.inicio'))->assertOk()->assertSee('Avisos, 1 não lido')->assertSee(route('consulta.avisos'));
    }
}
