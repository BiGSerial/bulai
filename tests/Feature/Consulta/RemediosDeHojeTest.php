<?php

namespace Tests\Feature\Consulta;

use App\Livewire\Consulta\Inicio;
use App\Livewire\Consulta\Perfil;
use App\Livewire\Consulta\Perguntar;
use App\Livewire\Consulta\Tratamento\Hoje;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\MedicationDoseEvent;
use App\Models\PatientMedication;
use App\Models\PatientProfile;
use App\Models\UnmatchedMedicineReport;
use App\Models\User;
use App\Services\Patient\TreatmentContextService;
use App\Services\Treatment\TreatmentScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Tela "Remédios de hoje", Início com a próxima dose, cadastro pelo nome no perfil e contexto com remédio sem catálogo. */
final class RemediosDeHojeTest extends TestCase
{
    use RefreshDatabase;

    private function patient(): User
    {
        $user = User::factory()->create(['name' => 'Maria Silva']);
        PatientProfile::query()->create(['user_id' => $user->id, 'health_consent_at' => now(), 'timezone' => 'America/Sao_Paulo']);

        return $user;
    }

    private function medicine(string $name, string $ingredient): AnvisaMedicine
    {
        $medicine = AnvisaMedicine::factory()->create(['product_name' => $name, 'normalized_product_name' => strtolower($name), 'active_ingredient' => $ingredient, 'normalized_active_ingredient' => strtolower($ingredient)]);
        $medicine->ingredients()->attach(ActiveIngredient::factory()->create(['canonical_name' => $ingredient, 'normalized_name' => strtolower($ingredient)])->id, ['component_text' => $ingredient, 'method' => 'alias', 'confidence' => 1]);

        return $medicine;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_today_lists_each_scheduled_time_grouped_by_period_and_marks_taken_skipped_and_undo(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 14:10', 'America/Sao_Paulo'));
        Carbon::setTestNow(Carbon::parse('2026-11-05 14:10', 'America/Sao_Paulo'));
        $user = $this->patient();
        $service = app(TreatmentScheduleService::class);
        $losartana = $service->register($user, ['medicine_id' => $this->medicine('LOSARTANA', 'LOSARTANA')->id, 'dosage' => '1 comprimido', 'frequency' => '8/8h', 'started_at' => '2026-11-01', 'schedule_mode' => PatientMedication::MODE_FIXED]);
        $service->register($user, ['name_as_written' => 'Xarope Novo', 'frequency' => 'à noite', 'started_at' => '2026-11-05']);

        $component = Livewire::actingAs($user)->test(Hoje::class)
            ->assertSee('Remédios de hoje')->assertSee('Manhã')->assertSee('Tarde')->assertSee('Noite')
            ->assertSee('06:00')->assertSee('14:00')->assertSee('22:00')->assertSee('20:00')->assertSee('Xarope Novo')->assertSee('1 comprimido')
            ->assertSee('Atrasado')->assertSee('Agora')->assertSee('A tomar')->assertSee('0 de 4 horários marcados')->assertSeeHtml('wire:poll.60s');

        $morning = CarbonImmutable::parse('2026-11-05 06:00', 'America/Sao_Paulo')->utc()->toIso8601String();
        $afternoon = CarbonImmutable::parse('2026-11-05 14:00', 'America/Sao_Paulo')->utc()->toIso8601String();
        $component->call('tomei', $losartana->id, $afternoon)->assertSee('Tomei')->assertSee('1 de 4 horários marcados')
            ->call('pular', $losartana->id, $morning)->assertSee('Pulei')->assertSee('2 de 4 horários marcados')->assertSee('Desfazer');
        $this->assertSame([MedicationDoseEvent::STATUS_SKIPPED, MedicationDoseEvent::STATUS_TAKEN], MedicationDoseEvent::query()->orderBy('scheduled_for')->pluck('status')->all());

        $component->call('desfazer', $losartana->id, $morning)->assertSee('1 de 4 horários marcados');
        $this->assertSame(1, MedicationDoseEvent::query()->count());

        $component->call('mudarDia', 1)->assertSee('Remédios de amanhã')->assertSee('0 de 4 horários marcados')
            ->call('mudarDia', 1)->assertSet('dia', 1)
            ->call('mudarDia', -2)->assertSee('Remédios de ontem')->assertDontSee('Xarope Novo');
    }

    public function test_today_is_private_empty_states_guide_the_patient_and_home_shows_the_next_dose(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 09:00', 'America/Sao_Paulo'));
        Carbon::setTestNow(Carbon::parse('2026-11-05 09:00', 'America/Sao_Paulo'));
        $user = $this->patient();
        $this->get(route('consulta.hoje'))->assertRedirectToRoute('login');

        Livewire::actingAs($user)->test(Hoje::class)->assertSee('Você ainda não tem remédios programados')->assertSee('Adicionar remédio')->assertSee('Fotografar receita')->assertSee('Meus remédios')->assertDontSee('horários marcados');
        Livewire::actingAs($user)->test(Inicio::class)->assertSee('Remédios de hoje')->assertSee('Veja os horários e marque o que já tomou')->assertSee('Meus remédios')->assertSee(route('consulta.hoje'));

        $medication = app(TreatmentScheduleService::class)->register($user, ['name_as_written' => 'Remédio Azul', 'frequency' => '2x ao dia']);
        // Cartão do dia na Início: progresso das doses e "Marcar como tomado" quando a próxima está na janela.
        Livewire::actingAs($user)->test(Inicio::class)->assertSee('Atrasado: 08:00 · Remédio Azul')->assertSee('0 de 2 doses tomadas')->assertSee('Marcar como tomado')->assertSeeHtml('aria-label="0 de 2 doses"');
        $iso = CarbonImmutable::parse('2026-11-05 08:00', 'America/Sao_Paulo')->utc()->toIso8601String();
        Livewire::actingAs($user)->test(Inicio::class)->call('tomei', $medication->id, $iso)->assertSee('1 de 2 doses tomadas')->assertSee('Próximo: 20:00 · Remédio Azul')->assertDontSee('Marcar como tomado');
        $this->assertSame(1, MedicationDoseEvent::query()->count());
        Livewire::actingAs($user)->test(Hoje::class)->assertSee('1 de 2 horários marcados')->call('irPara', 1)->assertSee('Remédios de amanhã')->call('irPara', 0)->assertSee('Remédios de hoje');

        // Outro paciente não vê nem marca as doses deste.
        $other = $this->patient();
        Livewire::actingAs($other)->test(Hoje::class)->assertDontSee('Remédio Azul')->call('tomei', $medication->id, $iso);
        Livewire::actingAs($other)->test(Inicio::class)->assertDontSee('Remédio Azul')->call('tomei', $medication->id, $iso);
        $this->assertSame(1, MedicationDoseEvent::query()->count());

        Livewire::actingAs(User::factory()->create())->test(Hoje::class)->assertSee('autorize guardar seus dados de saúde');
    }

    public function test_profile_registers_an_unknown_medicine_by_name_with_times_and_the_question_context_flags_it(): void
    {
        $user = $this->patient();
        $asked = $this->medicine('MAREVAN', 'VARFARINA');

        $component = Livewire::actingAs($user)->test(Perfil::class)
            ->set('busca', 'Fitoterápico da Vovó')->assertSee('Não achamos')->assertSee('cadastrar assim mesmo')
            ->call('cadastrarPeloNome')->assertSee('entrou na sua lista')->assertSee('Não encontrado no catálogo: vamos conferir')->assertDontSee(route('consulta.bula', 1, false));
        $medication = PatientMedication::query()->sole();
        $this->assertSame([null, 'Fitoterápico da Vovó', PatientMedication::CATALOG_UNMATCHED, $medication->id], [$medication->medicine_id, $medication->name_as_written, $medication->catalog_status, $component->get('editandoId')]);
        $this->assertSame(1, UnmatchedMedicineReport::query()->open()->where('user_id', $user->id)->count());

        $component->set('posologia.frequency', '12/12h')->assertSet('posologia.times', ['08:00', '20:00'])->assertSet('posologia.schedule_mode', PatientMedication::MODE_INTERVAL)->set('posologia.schedule_mode', PatientMedication::MODE_FIXED)
            ->call('tirarHorario', 1)->call('adicionarHorario')->set('posologia.times.1', '21:00')->set('posologia.reminders_enabled', false)
            ->call('salvarPosologia')->assertSet('editandoId', null)->assertSee('às 08:00, 21:00');
        $this->assertSame([['08:00', '21:00'], false, false], [$medication->refresh()->dose_times, $medication->as_needed, $medication->reminders_enabled]);

        $component->call('editarPosologia', $medication->id)->set('posologia.as_needed', true)->call('salvarPosologia')->assertSee('Só quando precisar');
        $this->assertSame([[], true], [$medication->refresh()->dose_times, $medication->as_needed]);

        $context = app(TreatmentContextService::class)->for($user, $asked);
        $this->assertSame(['Fitoterápico da Vovó (sem bula cadastrada no BULA-IA)'], $context['unresolved']);
        $this->assertSame(1, $context['medications_count']);
        $this->assertNull(app(TreatmentContextService::class)->alertsText($context, 'MAREVAN'), 'sem interação nem alergia, nada do perfil vai para a resposta');
        Livewire::actingAs($user)->test(Perguntar::class)->set('pergunta', 'posso tomar marevan?')->call('perguntar')->assertSee('Não foi possível verificar: Fitoterápico da Vovó');

        $component->call('removerMedicamento', $medication->id);
        $this->assertSame([0, 0], [PatientMedication::query()->count(), UnmatchedMedicineReport::query()->count()]);
    }

    public function test_interval_medicines_show_the_real_time_and_the_recalculated_doses_and_the_mode_can_be_chosen(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 09:30', 'America/Sao_Paulo'));
        Carbon::setTestNow(Carbon::parse('2026-11-05 09:30', 'America/Sao_Paulo'));
        $user = $this->patient();
        $service = app(TreatmentScheduleService::class);
        $amoxicilina = $service->register($user, ['name_as_written' => 'Amoxicilina', 'dosage' => '1 cápsula', 'frequency' => 'de 8 em 8 horas', 'started_at' => '2026-11-05']);
        $this->assertTrue($amoxicilina->isDynamic());

        // Antes de marcar: cadeia a partir do primeiro horário; depois de "Tomei" às 09:30, a próxima passa a ser 17:30.
        $component = Livewire::actingAs($user)->test(Hoje::class)
            ->assertSee('a cada 8 h')->assertSee('se ajustam ao horário')->assertSee('06:00')->assertSee('14:00')->assertSee('22:00')->assertDontSee('Ajustado');
        $six = CarbonImmutable::parse('2026-11-05 06:00', 'America/Sao_Paulo')->utc()->toIso8601String();
        $component->call('tomei', $amoxicilina->id, $six)
            ->assertSee('Tomei 09:30')->assertSee('17:30')->assertSee('Ajustado')->assertSee('contado a partir das 09:30')->assertDontSee('14:00')->assertDontSee('22:00');
        Livewire::actingAs($user)->test(Inicio::class)->assertSee('Próximo: 17:30 · Amoxicilina · ajustado');
        $component->call('irPara', 1)->assertSee('01:30')->assertSee('09:30')->assertSee('17:30');

        // Meus remédios: o paciente vê o modo e pode preferir horários fixos.
        $perfil = Livewire::actingAs($user)->test(Perfil::class)->assertSee('a cada 8 h, a partir da última dose')
            ->call('editarPosologia', $amoxicilina->id)->assertSet('posologia.schedule_mode', PatientMedication::MODE_INTERVAL)->assertSet('posologia.interval_hours', 8)
            ->assertSee('Contar a partir de quando eu tomar')->assertSee('Primeiro horário do dia')
            ->set('posologia.schedule_mode', PatientMedication::MODE_FIXED)->assertSee('+ Adicionar horário')->call('salvarPosologia')->assertSet('editandoId', null);
        $this->assertSame([PatientMedication::MODE_FIXED, 480, false], [$amoxicilina->refresh()->schedule_mode, $amoxicilina->interval_minutes, $amoxicilina->isDynamic()]);
        Livewire::actingAs($user)->test(Hoje::class)->assertSee('14:00')->assertDontSee('Ajustado');

        // Voltar ao modo dinâmico por outra frequência: só o primeiro horário é guardado como partida da cadeia.
        $perfil->call('editarPosologia', $amoxicilina->id)->set('posologia.frequency', '12/12h')->assertSet('posologia.interval_hours', 12)->assertSet('posologia.schedule_mode', PatientMedication::MODE_INTERVAL)->call('salvarPosologia');
        $this->assertSame([PatientMedication::MODE_INTERVAL, 720, ['06:00']], [$amoxicilina->refresh()->schedule_mode, $amoxicilina->interval_minutes, $amoxicilina->dose_times]);
    }
}
