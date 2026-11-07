<?php

namespace Tests\Feature\Infrastructure;

use App\Models\PatientMedication;
use App\Models\User;
use App\Notifications\MedicationReminderNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\TemporaryPasswordNotification;
use App\Notifications\TreatmentEndingNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Tests\TestCase;

/**
 * Identidade do BULA-IA nos e-mails: cabeçalho com símbolo e nome (PNG com endereço absoluto), cores da marca, botão laranja,
 * painel de destaque, rodapé em português com o aviso do produto, e nada do modelo padrão do Laravel (inglês, "Laravel", "Regards").
 */
final class EmailIdentidadeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.name' => 'BULA-IA', 'app.url' => 'https://bulai.vixforge.com.br']);
    }

    private function medication(User $user): PatientMedication
    {
        return PatientMedication::query()->create([
            'user_id' => $user->id, 'medicine_id' => null, 'name_as_written' => 'Losartana', 'catalog_status' => PatientMedication::CATALOG_UNMATCHED,
            'dosage' => '50 mg', 'frequency' => '24/24h', 'dose_times' => ['08:00'], 'started_at' => '2026-11-01',
        ]);
    }

    /** @return list<array{string, string}> */
    private function renderAll(): array
    {
        $user = User::factory()->create(['name' => 'Maria Silva']);
        $medication = $this->medication($user);
        $when = CarbonImmutable::parse('2026-11-07 08:00', 'America/Sao_Paulo');

        return [
            ['senha provisória', (string) (new TemporaryPasswordNotification('Abc-1234-xyz', true))->toMail($user)->render()],
            ['nova senha', (string) (new TemporaryPasswordNotification('Zzz-9999-abc', false))->toMail($user)->render()],
            ['redefinição', (string) (new ResetPasswordNotification('token-de-teste'))->toMail($user)->render()],
            ['lembrete de dose', (string) (new MedicationReminderNotification($medication, $when, true))->toMail($user)->render()],
            ['fim do tratamento', (string) (new TreatmentEndingNotification($medication, $when, true))->toMail($user)->render()],
        ];
    }

    public function test_every_email_carries_the_brand_header_colors_button_and_portuguese_footer(): void
    {
        foreach ($this->renderAll() as [$name, $html]) {
            $this->assertStringContainsString('https://bulai.vixforge.com.br/brand/bulai-icone-192.png', $html, "{$name}: símbolo em PNG com endereço absoluto");
            $this->assertStringContainsString('Bulas inteligentes', $html, "{$name}: lema da marca");
            $this->assertMatchesRegularExpression('/background-color:\s*#12344d/i', $html, "{$name}: faixa azul-marinho do cabeçalho");
            $this->assertMatchesRegularExpression('/border-bottom:\s*4px solid #d9661f/i', $html, "{$name}: filete laranja");
            $this->assertMatchesRegularExpression('/background-color:\s*#ece6da/i', $html, "{$name}: fundo creme");
            $this->assertStringContainsString('Você recebeu esta mensagem porque tem uma conta no BULA-IA', $html, "{$name}: rodapé em português");
            $this->assertStringContainsString('não substitui a orientação de médicos ou farmacêuticos', $html, "{$name}: aviso do produto no rodapé");
            $this->assertStringContainsString('Equipe BULA-IA', $html, "{$name}: assinatura");
        }
    }

    public function test_no_email_keeps_the_generic_laravel_template(): void
    {
        foreach ($this->renderAll() as [$name, $html]) {
            foreach (['Laravel', 'All rights reserved', 'Regards', 'Hello!', "If you're having trouble", 'laravel.com'] as $generic) {
                $this->assertStringNotContainsString($generic, $html, "{$name}: sobrou texto genérico “{$generic}”");
            }
            $this->assertStringNotContainsString('#18181b', $html, "{$name}: cor preta do tema padrão");
        }
    }

    public function test_the_buttons_use_the_accent_color_and_the_fallback_link_is_in_portuguese(): void
    {
        $user = User::factory()->create();
        $html = (string) (new ResetPasswordNotification('token-de-teste'))->toMail($user)->render();

        $this->assertMatchesRegularExpression('/background-color:\s*#b8521a/i', $html);
        $this->assertStringContainsString('Redefinir minha senha', $html);
        $this->assertStringContainsString('Se o botão “Redefinir minha senha” não funcionar, copie e cole o endereço abaixo no navegador:', $html);
    }

    public function test_the_temporary_password_goes_in_a_highlight_panel_with_the_next_steps(): void
    {
        $user = User::factory()->create(['name' => 'Maria Silva']);

        $new = (string) (new TemporaryPasswordNotification('Abc-1234-xyz', true))->toMail($user)->render();
        $this->assertStringContainsString('Olá, Maria Silva!', $new);
        $this->assertStringContainsString('Um administrador criou o seu acesso ao BULA-IA.', $new);
        $this->assertMatchesRegularExpression('/<code[^>]*>Abc-1234-xyz<\/code>/', $new, 'a senha em destaque monoespaçado');
        $this->assertMatchesRegularExpression('/border-left:\s*#12344d solid 5px/i', $new, 'painel com barra azul-marinho');
        $this->assertStringContainsString('No primeiro acesso você vai criar uma senha só sua.', $new);
        $this->assertStringContainsString('Entrar no BULA-IA', $new);

        $reset = (string) (new TemporaryPasswordNotification('Zzz-9999-abc', false))->toMail($user)->render();
        $this->assertStringContainsString('Um administrador gerou uma nova senha provisória', $reset);
        $this->assertSame('Seu acesso ao BULA-IA', (new TemporaryPasswordNotification('x', true))->toMail($user)->subject);
        $this->assertSame('Nova senha provisória · BULA-IA', (new TemporaryPasswordNotification('x', false))->toMail($user)->subject);
    }

    public function test_the_dose_reminder_highlights_the_time_and_the_medicine(): void
    {
        $user = User::factory()->create(['name' => 'Maria Silva']);
        $medication = $this->medication($user);
        $notification = new MedicationReminderNotification($medication, CarbonImmutable::parse('2026-11-07 08:00', 'America/Sao_Paulo'), true);

        $html = (string) $notification->toMail($user)->render();

        $this->assertStringContainsString('Está na hora do seu remédio.', $html);
        $this->assertStringContainsString('Às 08:00', $html);
        $this->assertStringContainsString($medication->displayName().', 50 mg', $html);
        $this->assertStringContainsString('Ver remédios de hoje', $html);
        $this->assertStringContainsString('Para parar de receber lembretes por e-mail', $html);
        $this->assertStringContainsString($medication->displayName().' às 08:00 · BULA-IA', (string) $notification->toMail($user)->subject, 'o assunto continua com o remédio e o horário');
    }

    public function test_the_plain_text_version_also_carries_the_brand_and_the_disclaimer(): void
    {
        $user = User::factory()->create();
        $mail = (new ResetPasswordNotification('token-de-teste'))->toMail($user);
        $text = (string) app(Markdown::class)->renderText('notifications::email', $mail->data());

        $this->assertStringContainsString('BULA-IA · Bulas inteligentes', $text);
        $this->assertStringContainsString('não substitui a orientação de médicos ou farmacêuticos', $text);
        $this->assertStringNotContainsString('Laravel', $text);
    }
}
