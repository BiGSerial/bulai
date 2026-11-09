<?php

namespace Tests\Feature\Consulta;

use App\Livewire\Consulta\Avisos;
use App\Livewire\Consulta\Cuidado\CuidarDeAlguem;
use App\Livewire\Consulta\Cuidado\QuemCuidaDeMim;
use App\Livewire\Consulta\Cuidado\Remedios;
use App\Livewire\Consulta\Tratamento\Hoje;
use App\Models\CaregiverAuditEvent;
use App\Models\CaregiverLink;
use App\Models\MedicationDoseEvent;
use App\Models\PatientMedication;
use App\Models\PatientProfile;
use App\Models\User;
use App\Notifications\CaregiverLateDoseNotification;
use App\Notifications\CaregiverRequestNotification;
use App\Services\Caregiving\CaregiverLinkService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Modo cuidador: convite com handshake, permissões no servidor, revogação, consentimento, troca de perfil, alertas e auditoria. */
final class ModoCuidadorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function freeze(string $local): void
    {
        Carbon::setTestNow(Carbon::parse($local, 'America/Sao_Paulo'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($local, 'America/Sao_Paulo'));
    }

    private function patient(string $name = 'Maria Silva', bool $consent = true): User
    {
        $user = User::factory()->create(['name' => $name]);
        PatientProfile::query()->create(['user_id' => $user->id, 'health_consent_at' => $consent ? now() : null, 'timezone' => 'America/Sao_Paulo']);

        return $user;
    }

    private function medication(User $user, string $name, array $attributes = []): PatientMedication
    {
        return PatientMedication::query()->create($attributes + [
            'user_id' => $user->id, 'medicine_id' => null, 'name_as_written' => $name, 'catalog_status' => PatientMedication::CATALOG_UNMATCHED,
            'dosage' => '1 comprimido', 'frequency' => '1x ao dia', 'dose_times' => ['08:00'], 'started_at' => '2026-11-01',
        ]);
    }

    /** @param array<string, bool> $permissions */
    private function link(User $patient, User $caregiver, array $permissions = []): CaregiverLink
    {
        return CaregiverLink::factory()->withPermissions($permissions)->create(['patient_id' => $patient->id, 'caregiver_id' => $caregiver->id]);
    }

    private function iso(string $local): string
    {
        return CarbonImmutable::parse($local, 'America/Sao_Paulo')->utc()->toIso8601String();
    }

    public function test_invite_code_lasts_48_hours_is_single_use_and_pending_until_the_patient_approves(): void
    {
        $this->freeze('2026-11-05 10:00');
        $patient = $this->patient();
        $ana = User::factory()->create(['name' => 'Ana Souza']);
        $outra = User::factory()->create();
        $this->get(route('consulta.cuidadores'))->assertRedirect(route('login'));
        $this->get(route('consulta.cuidar', ['codigo' => '12345678']))->assertRedirect(route('login'));

        Livewire::actingAs($patient)->test(QuemCuidaDeMim::class)->call('gerarConvite')->assertSee('Convite criado');
        $invite = CaregiverLink::query()->where('patient_id', $patient->id)->sole();
        $code = (string) $invite->invite_code;
        $this->assertSame(CaregiverLink::STATUS_INVITED, $invite->status);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $code);
        $this->assertTrue($invite->invite_expires_at->equalTo(now()->addHours(48)));
        $this->actingAs($patient)->get(route('consulta.cuidadores'))->assertOk()->assertSee(CaregiverLink::formatCode($code));

        // O link do convite já traz o código preenchido.
        Livewire::actingAs($ana)->withQueryParams(['codigo' => $code])->test(CuidarDeAlguem::class)->assertSet('codigo', $code);
        Livewire::actingAs($ana)->test(CuidarDeAlguem::class)->set('codigo', CaregiverLink::formatCode($code))->call('usarCodigo')
            ->assertSee('Agora falta Maria aprovar');
        $invite->refresh();
        $this->assertSame([CaregiverLink::STATUS_PENDING, $ana->id, null], [$invite->status, $invite->caregiver_id, $invite->invite_code]);
        $this->assertSame(CaregiverRequestNotification::class, $patient->notifications()->sole()->type);
        // Pendente não dá acesso a nada.
        $this->actingAs($ana)->get(route('consulta.cuidando.hoje', $invite))->assertForbidden();

        Livewire::actingAs($outra)->test(CuidarDeAlguem::class)->set('codigo', $code)->call('usarCodigo')->assertSee('Este código já foi usado ou cancelado');
        Livewire::actingAs($outra)->test(CuidarDeAlguem::class)->set('codigo', '0000 0001')->call('usarCodigo')->assertSee('Não encontramos este código');

        Livewire::actingAs($patient)->test(QuemCuidaDeMim::class)->call('gerarConvite');
        $second = CaregiverLink::query()->where('patient_id', $patient->id)->where('status', CaregiverLink::STATUS_INVITED)->sole();
        $this->travel(49)->hours();
        Livewire::actingAs($outra)->test(CuidarDeAlguem::class)->set('codigo', (string) $second->invite_code)->call('usarCodigo')->assertSee('Este código venceu');
        $this->assertSame(CaregiverLink::STATUS_INVITED, $second->refresh()->status);
        $this->assertSame(
            [CaregiverAuditEvent::EVENT_INVITE_CREATED, CaregiverAuditEvent::EVENT_INVITE_ACCEPTED],
            CaregiverAuditEvent::query()->where('caregiver_link_id', $invite->id)->orderBy('id')->pluck('event')->all(),
        );
    }

    public function test_a_new_invite_cancels_the_previous_code_and_the_patient_cannot_use_their_own(): void
    {
        $patient = $this->patient();
        $service = app(CaregiverLinkService::class);
        $first = $service->createInvite($patient);
        $code = (string) $first->invite_code;
        $service->createInvite($patient);

        Livewire::actingAs(User::factory()->create())->test(CuidarDeAlguem::class)->set('codigo', $code)->call('usarCodigo')->assertSee('já foi usado ou cancelado');
        Livewire::actingAs($patient)->test(CuidarDeAlguem::class)
            ->set('codigo', (string) CaregiverLink::query()->where('status', CaregiverLink::STATUS_INVITED)->sole()->invite_code)->call('usarCodigo')
            ->assertSee('Este convite é seu');
    }

    public function test_wrong_codes_are_limited_to_five_attempts_per_person(): void
    {
        $patient = $this->patient();
        $code = (string) app(CaregiverLinkService::class)->createInvite($patient)->invite_code;
        $caregiver = User::factory()->create();
        $wrong = $code === '99999999' ? '99999998' : '99999999';

        $component = Livewire::actingAs($caregiver)->test(CuidarDeAlguem::class);
        foreach (range(1, CaregiverLinkService::MAX_CODE_ATTEMPTS) as $attempt) {
            $component->set('codigo', $wrong)->call('usarCodigo')->assertSee('Não encontramos este código');
        }
        $component->set('codigo', $code)->call('usarCodigo')->assertSee('Muitas tentativas com código errado. Espere 15 minutos');
        $this->assertSame(CaregiverLink::STATUS_INVITED, CaregiverLink::query()->sole()->status);

        // O limite é por pessoa: outra conta ainda consegue usar o código.
        Livewire::actingAs(User::factory()->create())->test(CuidarDeAlguem::class)->set('codigo', $code)->call('usarCodigo')->assertSee('falta Maria aprovar');
    }

    public function test_invite_needs_the_patient_health_consent(): void
    {
        $patient = $this->patient(consent: false);

        Livewire::actingAs($patient)->test(QuemCuidaDeMim::class)->assertSee('primeiro autorize guardar seus dados de saúde')->call('gerarConvite')
            ->assertSee('Para convidar alguém, primeiro autorize');
        $this->assertSame(0, CaregiverLink::query()->count());
    }

    public function test_only_the_patient_approves_and_chooses_the_permissions(): void
    {
        $patient = $this->patient();
        $caregiver = User::factory()->create(['name' => 'Ana Souza']);
        $link = CaregiverLink::factory()->pending()->create(['patient_id' => $patient->id, 'caregiver_id' => $caregiver->id]);

        Livewire::actingAs($caregiver)->test(QuemCuidaDeMim::class)->call('aprovar', $link->id)->assertForbidden();
        Livewire::actingAs(User::factory()->create())->test(QuemCuidaDeMim::class)->call('aprovar', $link->id)->assertForbidden();
        $this->assertTrue($link->refresh()->isPending());

        Livewire::actingAs($patient)->test(QuemCuidaDeMim::class)
            ->assertSee('Ana Souza quer acompanhar você')
            ->assertSet("permissoes.{$link->id}", [CaregiverLink::PERMISSION_ALERTS => true, CaregiverLink::PERMISSION_VIEW => true, CaregiverLink::PERMISSION_MANAGE => true])
            ->set("permissoes.{$link->id}.".CaregiverLink::PERMISSION_ALERTS, false)
            ->call('aprovar', $link->id)
            ->assertSee('Ana agora acompanha você');

        $link->refresh();
        $this->assertSame([CaregiverLink::STATUS_ACTIVE, false, true, true], [$link->status, $link->can_receive_alerts, $link->can_view_medications, $link->can_manage_medications]);
        $audit = CaregiverAuditEvent::query()->where('event', CaregiverAuditEvent::EVENT_APPROVED)->sole();
        $this->assertSame([$patient->id, $patient->id, false], [$audit->actor_id, $audit->patient_id, $audit->details[CaregiverLink::PERMISSION_ALERTS]]);
    }

    public function test_the_patient_can_reject_a_request_and_edit_permissions_later_with_manage_implying_view(): void
    {
        $patient = $this->patient();
        $rejected = CaregiverLink::factory()->pending()->create(['patient_id' => $patient->id]);
        $active = $this->link($patient, User::factory()->create());

        Livewire::actingAs($patient)->test(QuemCuidaDeMim::class)
            ->call('recusar', $rejected->id)->assertSee('Pedido recusado')
            ->set("permissoes.{$active->id}.".CaregiverLink::PERMISSION_VIEW, false)
            ->set("permissoes.{$active->id}.".CaregiverLink::PERMISSION_ALERTS, false)
            ->call('salvarPermissoes', $active->id);

        $this->assertSame(CaregiverLink::STATUS_REVOKED, $rejected->refresh()->status);
        $active->refresh();
        $this->assertSame([false, true, true], [$active->can_receive_alerts, $active->can_view_medications, $active->can_manage_medications]);
        $changed = CaregiverAuditEvent::query()->where('event', CaregiverAuditEvent::EVENT_PERMISSIONS_CHANGED)->sole();
        $this->assertTrue($changed->details['before'][CaregiverLink::PERMISSION_ALERTS]);
        $this->assertFalse($changed->details[CaregiverLink::PERMISSION_ALERTS]);
    }

    /** @return array<string, array{0: string, 1: array<string, bool>, 2: bool, 3: array{view: bool, markDose: bool, update: bool}}> */
    public static function accessMatrix(): array
    {
        $all = [CaregiverLink::PERMISSION_ALERTS => true, CaregiverLink::PERMISSION_VIEW => true, CaregiverLink::PERMISSION_MANAGE => true];

        return [
            'ativo com tudo' => [CaregiverLink::STATUS_ACTIVE, $all, true, ['view' => true, 'markDose' => true, 'update' => true]],
            'só ver' => [CaregiverLink::STATUS_ACTIVE, [CaregiverLink::PERMISSION_MANAGE => false] + $all, true, ['view' => true, 'markDose' => false, 'update' => false]],
            'marcar sem ver' => [CaregiverLink::STATUS_ACTIVE, [CaregiverLink::PERMISSION_VIEW => false] + $all, true, ['view' => false, 'markDose' => false, 'update' => false]],
            'só avisos' => [CaregiverLink::STATUS_ACTIVE, [CaregiverLink::PERMISSION_VIEW => false, CaregiverLink::PERMISSION_MANAGE => false] + $all, true, ['view' => false, 'markDose' => false, 'update' => false]],
            'pendente' => [CaregiverLink::STATUS_PENDING, $all, true, ['view' => false, 'markDose' => false, 'update' => false]],
            'revogado' => [CaregiverLink::STATUS_REVOKED, $all, true, ['view' => false, 'markDose' => false, 'update' => false]],
            'saiu' => [CaregiverLink::STATUS_LEFT, $all, true, ['view' => false, 'markDose' => false, 'update' => false]],
            'sem consentimento' => [CaregiverLink::STATUS_ACTIVE, $all, false, ['view' => false, 'markDose' => false, 'update' => false]],
        ];
    }

    /**
     * @param  array<string, bool>  $permissions
     * @param  array{view: bool, markDose: bool, update: bool}  $expected
     */
    #[DataProvider('accessMatrix')]
    public function test_medication_policy_follows_link_status_permissions_and_consent(string $status, array $permissions, bool $consent, array $expected): void
    {
        $patient = $this->patient(consent: $consent);
        $caregiver = User::factory()->create();
        CaregiverLink::factory()->withPermissions($permissions)->create(['patient_id' => $patient->id, 'caregiver_id' => $caregiver->id, 'status' => $status]);
        $medication = $this->medication($patient, 'Losartana');

        foreach ($expected as $ability => $allowed) {
            $this->assertSame($allowed, Gate::forUser($caregiver)->allows($ability, $medication), $ability);
            $this->assertTrue(Gate::forUser($patient)->allows($ability, $medication), "dono: {$ability}");
            $this->assertFalse(Gate::forUser(User::factory()->create())->allows($ability, $medication), "estranho: {$ability}");
        }
    }

    public function test_caregiver_with_permission_marks_doses_and_edits_medications_with_audit(): void
    {
        $this->freeze('2026-11-05 08:30');
        $patient = $this->patient();
        $caregiver = User::factory()->create(['name' => 'Ana Souza']);
        $link = $this->link($patient, $caregiver);
        $losartana = $this->medication($patient, 'Losartana');

        Livewire::actingAs($caregiver)->test(Hoje::class, ['vinculo' => $link])
            ->call('tomei', $losartana->id, $this->iso('2026-11-05 08:00'))
            ->assertDispatched('notify', message: 'Anotado: Maria tomou Losartana.');
        $event = MedicationDoseEvent::query()->sole();
        $this->assertSame([$patient->id, MedicationDoseEvent::STATUS_TAKEN], [$event->user_id, $event->status]);

        Livewire::actingAs($caregiver)->test(Remedios::class, ['vinculo' => $link])
            ->call('editarPosologia', $losartana->id)
            ->set('posologia.dosage', '2 comprimidos')
            ->set('posologia.times', ['09:00'])
            ->call('salvarPosologia')
            ->assertSee('Dose e horários de Losartana salvos');
        $this->assertSame(['2 comprimidos', ['09:00']], [$losartana->refresh()->dosage, $losartana->dose_times]);

        $audit = CaregiverAuditEvent::query()->where('actor_id', $caregiver->id)->orderBy('id')->get();
        $this->assertSame([CaregiverAuditEvent::EVENT_DOSE_MARKED, CaregiverAuditEvent::EVENT_MEDICATION_UPDATED], $audit->pluck('event')->all());
        $this->assertSame(['08:00', MedicationDoseEvent::STATUS_TAKEN, $patient->id], [$audit[0]->details['time'], $audit[0]->details['status'], $audit[0]->patient_id]);
        $this->assertContains('dosage', $audit[1]->details['changed']);

        // O paciente vê o histórico do vínculo.
        Livewire::actingAs($patient)->test(QuemCuidaDeMim::class)->call('alternarHistorico', $link->id)
            ->assertSee('Ana marcou Losartana das 08:00 como tomado')
            ->assertSee('Ana mudou a dose ou os horários de Losartana');
        Livewire::actingAs($caregiver)->test(QuemCuidaDeMim::class)->call('alternarHistorico', $link->id)->assertForbidden();
    }

    public function test_caregiver_without_manage_permission_gets_403_to_mark_or_edit(): void
    {
        $this->freeze('2026-11-05 08:30');
        $patient = $this->patient();
        $caregiver = User::factory()->create();
        $link = $this->link($patient, $caregiver, [CaregiverLink::PERMISSION_MANAGE => false]);
        $losartana = $this->medication($patient, 'Losartana');

        Livewire::actingAs($caregiver)->test(Hoje::class, ['vinculo' => $link])
            ->assertSee('Losartana')->assertSee('não liberou marcar doses')->assertDontSee('Tomei</span>', false)
            ->call('tomei', $losartana->id, $this->iso('2026-11-05 08:00'))->assertForbidden();
        Livewire::actingAs($caregiver)->test(Remedios::class, ['vinculo' => $link])
            ->assertSee('não liberou mudar dose e horários')
            ->call('editarPosologia', $losartana->id)->assertForbidden();

        $this->assertSame(0, MedicationDoseEvent::query()->count());
        $this->assertSame(0, CaregiverAuditEvent::query()->whereIn('event', [CaregiverAuditEvent::EVENT_DOSE_MARKED, CaregiverAuditEvent::EVENT_MEDICATION_UPDATED])->count());
    }

    public function test_caregiver_without_view_permission_or_link_cannot_open_the_patient_screens(): void
    {
        $patient = $this->patient();
        $caregiver = User::factory()->create();
        $link = $this->link($patient, $caregiver, [CaregiverLink::PERMISSION_VIEW => false, CaregiverLink::PERMISSION_MANAGE => false]);
        $this->medication($patient, 'Losartana');

        $this->actingAs($caregiver)->get(route('consulta.cuidando.hoje', $link))->assertForbidden();
        $this->actingAs($caregiver)->get(route('consulta.cuidando.remedios', $link))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('consulta.cuidando.hoje', $this->link($patient, User::factory()->create())))->assertForbidden();
    }

    public function test_revoking_or_leaving_cuts_access_immediately(): void
    {
        $this->freeze('2026-11-05 08:30');
        $patient = $this->patient();
        $caregiver = User::factory()->create(['name' => 'Ana Souza']);
        $link = $this->link($patient, $caregiver);
        $losartana = $this->medication($patient, 'Losartana');
        $open = Livewire::actingAs($caregiver)->test(Hoje::class, ['vinculo' => $link])->assertSee('Losartana');

        Livewire::actingAs($patient)->test(QuemCuidaDeMim::class)->call('revogar', $link->id)->assertSee('Ana não tem mais acesso');

        $open->call('tomei', $losartana->id, $this->iso('2026-11-05 08:00'))->assertForbidden();
        $this->actingAs($caregiver)->get(route('consulta.cuidando.hoje', $link))->assertForbidden();
        $this->assertSame(0, MedicationDoseEvent::query()->count());
        $this->assertSame(CaregiverAuditEvent::EVENT_REVOKED, CaregiverAuditEvent::query()->latest('id')->first()->event);

        $other = $this->link($patient, User::factory()->create());
        Livewire::actingAs($caregiver)->test(CuidarDeAlguem::class)->call('sair', $other->id)->assertForbidden();
        Livewire::actingAs($other->caregiver)->test(CuidarDeAlguem::class)->call('sair', $other->id)->assertSee('Você deixou de acompanhar Maria');
        $this->assertSame(CaregiverLink::STATUS_LEFT, $other->refresh()->status);
        $this->actingAs($other->caregiver)->get(route('consulta.cuidando.remedios', $other))->assertForbidden();
    }

    public function test_without_the_patient_health_consent_the_caregiver_sees_nothing_and_the_reason(): void
    {
        $patient = $this->patient(consent: false);
        $caregiver = User::factory()->create();
        $link = $this->link($patient, $caregiver);
        $this->medication($patient, 'Losartana');

        $this->actingAs($caregiver)->get(route('consulta.cuidando.hoje', $link))->assertForbidden();
        $this->actingAs($caregiver)->get(route('consulta.cuidar'))->assertOk()
            ->assertSee('Maria ainda não autorizou guardar dados de saúde')
            ->assertDontSee(route('consulta.cuidando.hoje', $link));
    }

    public function test_profile_switch_shows_the_right_person_data_and_whose_data_it_is(): void
    {
        $this->freeze('2026-11-05 07:00');
        $patient = $this->patient();
        $caregiver = $this->patient('Ana Souza');
        $link = $this->link($patient, $caregiver);
        $this->medication($patient, 'Losartana');
        $this->medication($caregiver, 'Sinvastatina');

        $this->actingAs($caregiver)->get(route('consulta.cuidando.hoje', $link))->assertOk()
            ->assertSee('Você está vendo os remédios de Maria Silva')
            ->assertSee('Losartana')->assertDontSee('Sinvastatina')
            ->assertSee('Cuidando de Maria Silva')->assertSee('Voltar para os meus');
        $this->actingAs($caregiver)->get(route('consulta.cuidando.remedios', $link))->assertOk()
            ->assertSee('Você está vendo os remédios de Maria Silva')->assertSee('Losartana')->assertDontSee('Sinvastatina');
        $this->actingAs($caregiver)->get(route('consulta.hoje'))->assertOk()
            ->assertSee('Sinvastatina')->assertDontSee('Losartana')->assertDontSee('Você está vendo os remédios de');
        $this->actingAs($caregiver)->get(route('consulta.inicio'))->assertOk()
            ->assertSee('Cuidando de Maria Silva')->assertSee('Remédios de Maria Silva')->assertSee(route('consulta.cuidando.hoje', $link));
    }

    public function test_late_dose_alert_reaches_only_caregivers_with_the_alert_permission_once(): void
    {
        $patient = $this->patient();
        $this->medication($patient, 'Losartana');
        $this->medication($patient, 'Sem lembrete', ['reminders_enabled' => false]);
        $withAlerts = User::factory()->create();
        $withoutAlerts = User::factory()->create();
        $link = $this->link($patient, $withAlerts);
        $this->link($patient, $withoutAlerts, [CaregiverLink::PERMISSION_ALERTS => false]);
        CaregiverLink::factory()->pending()->create(['patient_id' => $patient->id]);
        $noConsentPatient = $this->patient('Sem Consentimento', consent: false);
        $this->medication($noConsentPatient, 'Losartana');
        $this->link($noConsentPatient, $withAlerts);

        // 08:00 + 60 min de tolerância: ainda não atrasou às 08:55.
        $this->artisan('treatment:send-reminders', ['--now' => '2026-11-05T11:55:00+00:00'])->expectsOutputToContain('0 aviso(s) de dose atrasada')->assertSuccessful();
        $this->artisan('treatment:send-reminders', ['--now' => '2026-11-05T12:05:00+00:00'])->expectsOutputToContain('1 aviso(s) de dose atrasada')->assertSuccessful();
        $this->artisan('treatment:send-reminders', ['--now' => '2026-11-05T12:10:00+00:00'])->expectsOutputToContain('0 aviso(s) de dose atrasada')->assertSuccessful();

        $alert = DatabaseNotification::query()->where('type', CaregiverLateDoseNotification::class)->sole();
        $this->assertSame([$withAlerts->id, 'Remédio atrasado: Maria'], [$alert->notifiable_id, $alert->data['title']]);
        $this->assertStringContainsString('Losartana das 08:00', $alert->data['body']);
        $this->assertSame(0, $withoutAlerts->notifications()->count());

        // Marcada a dose, ou passada a janela de aviso, nada novo é enviado (dia seguinte, 3 h depois de atrasar).
        $this->artisan('treatment:send-reminders', ['--now' => '2026-11-06T15:05:00+00:00'])->expectsOutputToContain('0 aviso(s) de dose atrasada');

        // O aviso abre os remédios de hoje do paciente certo (sino/tela de avisos e push).
        Livewire::actingAs($withAlerts)->test(Avisos::class)->assertSee('Abrir os remédios de quem você cuida')
            ->call('abrir', $alert->id)->assertRedirect(route('consulta.cuidando.hoje', $link));
        $alert->refresh()->markAsUnread();
        $this->actingAs($withAlerts)->getJson(route('push.latest'))->assertJson(['url' => route('consulta.cuidando.hoje', $link, false), 'title' => 'Remédio atrasado: Maria']);
    }

    public function test_late_dose_alert_is_not_sent_for_a_marked_dose(): void
    {
        $patient = $this->patient();
        $losartana = $this->medication($patient, 'Losartana');
        $caregiver = User::factory()->create();
        $this->link($patient, $caregiver);
        MedicationDoseEvent::query()->create(['user_id' => $patient->id, 'patient_medication_id' => $losartana->id, 'scheduled_for' => CarbonImmutable::parse('2026-11-05 08:00', 'America/Sao_Paulo')->setTimezone(config('app.timezone')), 'status' => MedicationDoseEvent::STATUS_TAKEN, 'recorded_at' => now()]);

        $this->artisan('treatment:send-reminders', ['--now' => '2026-11-05T12:05:00+00:00'])->expectsOutputToContain('0 aviso(s) de dose atrasada');
        $this->assertSame(0, $caregiver->notifications()->count());
    }
}
