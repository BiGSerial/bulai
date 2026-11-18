<?php

namespace Tests\Feature\Consulta;

use App\Livewire\Consulta\Conta;
use App\Models\PatientMedication;
use App\Models\PatientProfile;
use App\Models\User;
use App\Models\UserAuditEvent;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** Conta do PWA (4º destino da barra, igual ao aplicativo): nome de chamada, senha e apagar a conta. */
final class ContaTest extends TestCase
{
    use RefreshDatabase;

    private function patient(): User
    {
        $user = User::factory()->create(['name' => 'Maria Aparecida Souza', 'email' => 'maria@exemplo.com']);
        PatientProfile::query()->create(['user_id' => $user->id, 'health_consent_at' => now()]);

        return $user;
    }

    public function test_the_account_screen_shows_who_is_connected_and_the_shortcuts(): void
    {
        $user = $this->patient();

        $this->actingAs($user)->get(route('consulta.conta'))->assertOk()
            ->assertSee('Olá, Maria!')->assertSee('Maria Aparecida Souza')->assertSee('maria@exemplo.com')
            ->assertSee('Como quer ser chamado(a)?')->assertSee('Aparência')->assertSee('Receber lembretes de horário')
            ->assertSee('Minhas perguntas')->assertSee(route('consulta.resumo'))->assertSee('Quem cuida de mim')->assertSee('Sair da conta')
            ->assertSee('Apagar minha conta')->assertDontSee('Área administrativa');
    }

    public function test_the_preferred_name_greets_the_person_and_empty_goes_back_to_the_first_name(): void
    {
        $user = $this->patient();

        Livewire::actingAs($user)->test(Conta::class)->set('nomeDeChamada', '  Cida ')->call('salvarNome')
            ->assertHasNoErrors()->assertSee('agora chamamos você de Cida');
        $this->assertSame('Cida', $user->refresh()->preferred_name);
        $this->actingAs($user)->get(route('consulta.inicio'))->assertSee('Olá, Cida');
        $this->assertSame(1, UserAuditEvent::query()->where('user_id', $user->id)->where('event', UserAuditEvent::EVENT_PREFERRED_NAME_CHANGED)->count());

        Livewire::actingAs($user)->test(Conta::class)->set('nomeDeChamada', '<b>')->call('salvarNome')->assertHasErrors('nomeDeChamada');
        Livewire::actingAs($user)->test(Conta::class)->set('nomeDeChamada', '')->call('salvarNome')->assertSee('vamos chamar você de Maria');
        $this->assertNull($user->refresh()->preferred_name);
    }

    public function test_changing_the_password_asks_the_current_one(): void
    {
        $user = $this->patient();

        Livewire::actingAs($user)->test(Conta::class)->set('senhaAtual', 'errada')->set('novaSenha', 'Nova-senha-2026')->set('novaSenha_confirmation', 'Nova-senha-2026')
            ->call('trocarSenha')->assertHasErrors('senhaAtual');
        Livewire::actingAs($user)->test(Conta::class)->set('senhaAtual', 'password')->set('novaSenha', 'password')->set('novaSenha_confirmation', 'password')
            ->call('trocarSenha')->assertHasErrors('novaSenha');

        Livewire::actingAs($user)->test(Conta::class)->set('senhaAtual', 'password')->set('novaSenha', 'Nova-senha-2026')->set('novaSenha_confirmation', 'Nova-senha-2026')
            ->call('trocarSenha')->assertHasNoErrors()->assertSee('Senha alterada')->assertSet('senhaAtual', '');
        $this->assertTrue(Hash::check('Nova-senha-2026', $user->refresh()->password));
    }

    public function test_deleting_the_account_asks_the_password_anonymizes_logs_out_and_blocks_administrators(): void
    {
        $user = $this->patient();
        PatientMedication::query()->create(['user_id' => $user->id, 'name_as_written' => 'Losartana', 'catalog_status' => PatientMedication::CATALOG_UNMATCHED]);

        Livewire::actingAs($user)->test(Conta::class)->set('senhaParaApagar', 'errada')->call('apagarConta')
            ->assertHasErrors(['senhaParaApagar'])->assertSee('A senha não confere.');
        $this->assertTrue($user->refresh()->active);

        Livewire::actingAs($user)->test(Conta::class)->set('senhaParaApagar', 'password')->call('apagarConta')->assertRedirect(route('login'));
        $user->refresh();
        $this->assertSame(['Conta apagada', false, 0], [$user->name, $user->active, PatientMedication::query()->where('user_id', $user->id)->count()]);
        $this->assertGuest();
        $this->get(route('login'))->assertSee('Sua conta foi apagada.');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Livewire::actingAs($admin)->test(Conta::class)->set('senhaParaApagar', 'password')->call('apagarConta')
            ->assertHasErrors('senhaParaApagar')->assertNoRedirect();
        $this->assertTrue($admin->refresh()->active);
    }
}
