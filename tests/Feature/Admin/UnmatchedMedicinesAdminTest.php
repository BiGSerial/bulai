<?php

namespace Tests\Feature\Admin;

use App\AdminNavigation;
use App\Livewire\Admin\Knowledge\Dashboard;
use App\Livewire\Admin\Knowledge\UnmatchedMedicines\Index;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientMedication;
use App\Models\PatientProfile;
use App\Models\UnmatchedMedicineReport;
use App\Models\User;
use App\Notifications\UnmatchedMedicineResolvedNotification;
use App\Services\Treatment\TreatmentScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Pendência "Remédios não encontrados": agrupamento por nome, ligação ao catálogo com aviso ao paciente e descarte. */
final class UnmatchedMedicinesAdminTest extends TestCase
{
    use RefreshDatabase;

    private function patient(): User
    {
        $user = User::factory()->create();
        PatientProfile::query()->create(['user_id' => $user->id, 'health_consent_at' => now()]);

        return $user;
    }

    private function medicine(string $name, string $ingredient): AnvisaMedicine
    {
        $medicine = AnvisaMedicine::factory()->create(['product_name' => $name, 'normalized_product_name' => strtolower($name), 'active_ingredient' => $ingredient, 'normalized_active_ingredient' => strtolower($ingredient), 'registration_holder' => 'Laboratório Exemplo']);
        $medicine->ingredients()->attach(ActiveIngredient::factory()->create(['canonical_name' => $ingredient, 'normalized_name' => strtolower($ingredient)])->id, ['component_text' => $ingredient, 'method' => 'alias', 'confidence' => 1]);

        return $medicine;
    }

    public function test_the_screen_requires_an_admin_and_the_menu_links_to_it(): void
    {
        $this->get(route('admin.knowledge.unmatched.index'))->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get(route('admin.knowledge.unmatched.index'))->assertForbidden();
        Livewire::actingAs(User::factory()->create())->test(Index::class)->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.knowledge.unmatched.index'))->assertOk()->assertSee('Remédios não encontrados')->assertSee('Nenhuma pendência');

        $routes = array_column(AdminNavigation::items(), 'route');
        $this->assertContains('admin.knowledge.unmatched.index', $routes);
        $this->assertSame(array_search('admin.knowledge.pending.index', $routes, true) + 1, array_search('admin.knowledge.unmatched.index', $routes, true));
    }

    public function test_open_reports_are_grouped_by_name_with_patient_counts_and_can_be_searched(): void
    {
        $schedule = app(TreatmentScheduleService::class);
        $ana = $this->patient();
        $bia = $this->patient();
        $schedule->register($ana, ['name_as_written' => 'Xarope Novo 10mg', 'dosage' => '5 ml', 'frequency' => '12/12h']);
        $schedule->register($bia, ['name_as_written' => 'XAROPE NOVO 10MG', 'dosage' => '10 ml']);
        $schedule->register($bia, ['name_as_written' => 'Pomada Azul']);

        $component = Livewire::actingAs(User::factory()->admin()->create())->test(Index::class)
            ->assertSee('2 nomes')->assertSee('3 pendências abertas')
            ->assertSee('2 pacientes')->assertSee('5 ml 12/12h · 10 ml')->assertSee('Pomada Azul')->assertSee('1 paciente');

        $component->set('search', 'pomada')->assertSee('1 nome')->assertSee('Pomada Azul')->assertDontSee('Xarope Novo');
        $component->set('search', '')->set('status', 'resolved')->assertSee('Nenhum remédio encontrado com estes filtros');
    }

    public function test_linking_resolves_every_open_report_with_that_name_merges_duplicates_and_notifies_each_patient(): void
    {
        Notification::fake();
        $schedule = app(TreatmentScheduleService::class);
        $amoxil = $this->medicine('AMOXIL', 'AMOXICILINA');
        $ana = $this->patient();
        $bia = $this->patient();
        $carla = $this->patient();
        $schedule->register($ana, ['name_as_written' => 'Amoxil 500', 'frequency' => '8/8h']);
        $schedule->register($bia, ['name_as_written' => 'amoxil 500', 'frequency' => '2x ao dia']);
        $schedule->register($bia, ['medicine_id' => $amoxil->id, 'frequency' => '1x ao dia']);
        $schedule->register($carla, ['name_as_written' => 'Outro remédio']);
        $admin = User::factory()->admin()->create();

        $component = Livewire::actingAs($admin)->test(Index::class)
            ->call('abrir', 'amoxil 500')->assertSet('openName', 'amoxil 500')->assertSet('catalogSearch', 'amoxil')
            ->assertSee('AMOXIL')->assertSee('AMOXICILINA')->assertSee('Laboratório Exemplo')
            ->set('note', 'Mesmo produto')
            ->call('ligar', $amoxil->id)
            ->assertSet('openName', null)
            ->assertDispatched('notify', fn (string $event, array $params): bool => str_contains($params['message'], '2 remédios ligados a AMOXIL'));

        $resolved = UnmatchedMedicineReport::query()->where('normalized_name', 'amoxil 500')->get();
        $this->assertCount(2, $resolved);
        $this->assertSame([UnmatchedMedicineReport::STATUS_RESOLVED], $resolved->pluck('status')->unique()->all());
        $this->assertSame([$amoxil->id], $resolved->pluck('resolved_medicine_id')->unique()->all());
        $this->assertSame([$admin->id], $resolved->pluck('resolved_by')->unique()->all());
        $this->assertSame(['Mesmo produto'], $resolved->pluck('resolution_note')->unique()->all());

        $this->assertSame($amoxil->id, PatientMedication::query()->where('user_id', $ana->id)->sole()->medicine_id);
        $biaMedication = PatientMedication::query()->where('user_id', $bia->id)->sole();
        $this->assertSame([$amoxil->id, PatientMedication::CATALOG_MATCHED, ['08:00', '20:00']], [$biaMedication->medicine_id, $biaMedication->catalog_status, $biaMedication->dose_times]);
        $this->assertSame(UnmatchedMedicineReport::STATUS_OPEN, UnmatchedMedicineReport::query()->where('user_id', $carla->id)->sole()->status);

        Notification::assertSentTo($ana, UnmatchedMedicineResolvedNotification::class);
        Notification::assertSentTo($bia, UnmatchedMedicineResolvedNotification::class);
        Notification::assertNotSentTo($carla, UnmatchedMedicineResolvedNotification::class);
        Notification::assertCount(2);

        $component->assertSee('Outro remédio')->assertDontSee('Amoxil 500');
        $component->set('status', 'resolved')->assertSee('Amoxil 500')->assertSee('Ligado a AMOXIL')->assertSee($admin->name);
    }

    public function test_linking_refuses_an_unknown_medicine_and_dismissing_keeps_the_medication_by_name(): void
    {
        $schedule = app(TreatmentScheduleService::class);
        $ana = $this->patient();
        $medication = $schedule->register($ana, ['name_as_written' => 'Pomada Azul', 'frequency' => '1x ao dia']);
        $admin = User::factory()->admin()->create();

        $component = Livewire::actingAs($admin)->test(Index::class)
            ->call('abrir', 'pomada azul')->call('ligar', 999999)
            ->assertDispatched('notify', fn (string $event, array $params): bool => $params['tone'] === 'warning')
            ->assertSet('openName', 'pomada azul');
        $this->assertSame(UnmatchedMedicineReport::STATUS_OPEN, UnmatchedMedicineReport::query()->sole()->status);

        $component->call('descartar', 'pomada azul')->assertSet('openName', null)
            ->assertDispatched('notify', fn (string $event, array $params): bool => str_contains($params['message'], 'Pendência descartada'))
            ->assertSee('Nenhuma pendência');
        $report = UnmatchedMedicineReport::query()->sole();
        $this->assertSame([UnmatchedMedicineReport::STATUS_DISMISSED, $admin->id], [$report->status, $report->resolved_by]);
        $this->assertNotNull($report->resolved_at);
        $this->assertSame([null, PatientMedication::CATALOG_UNMATCHED, 'Pomada Azul'], [$medication->refresh()->medicine_id, $medication->catalog_status, $medication->displayName()]);

        $component->set('status', 'dismissed')->assertSee('Pomada Azul')->assertSee('Descartado');
    }

    public function test_the_overview_counts_open_reports_as_a_point_of_attention(): void
    {
        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(Dashboard::class)->assertDontSee('sem correspondência no catálogo');

        app(TreatmentScheduleService::class)->register($this->patient(), ['name_as_written' => 'Pomada Azul']);
        Livewire::actingAs($admin)->test(Dashboard::class)->assertSee('remédios de pacientes sem correspondência no catálogo')->assertSeeHtml(route('admin.knowledge.unmatched.index'));
    }
}
