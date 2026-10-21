<?php

namespace Tests\Feature\Auth;

use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Knowledge\KnowledgeSourceService;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Drawer\Utils;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prova que as ações Livewire reaplicam os middlewares da rota original (persistent middleware).
 *
 * Livewire::test() não passa pelo endpoint real de update, então estes testes abrem a página por HTTP,
 * extraem o snapshot e fazem o POST com o cabeçalho X-Livewire, como o navegador faz. A tela usada é
 * a de Origens, que não tem checagem própria de perfil: quem barra é só o middleware persistente.
 */
class LivewirePersistentMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_livewire_action_is_refused_after_the_admin_is_demoted(): void
    {
        [$admin, $source, $snapshot] = $this->openSourcesPageAsAdmin();

        $admin->forceFill(['role' => UserRole::User])->save();
        $this->callToggleActive($admin, $snapshot, $source)->assertForbidden();

        $this->assertFalse($source->refresh()->active);
    }

    public function test_livewire_action_logs_out_an_admin_deactivated_with_the_page_open(): void
    {
        [$admin, $source, $snapshot] = $this->openSourcesPageAsAdmin();

        $admin->forceFill(['active' => false])->save();
        $this->callToggleActive($admin, $snapshot, $source)->assertRedirectToRoute('login');

        $this->assertGuest();
        $this->assertFalse($source->refresh()->active);
    }

    public function test_livewire_action_sends_an_admin_with_a_new_temporary_password_to_first_access(): void
    {
        [$admin, $source, $snapshot] = $this->openSourcesPageAsAdmin();

        $admin->forceFill(['must_change_password' => true])->save();
        $this->callToggleActive($admin, $snapshot, $source)->assertRedirectToRoute('first-access');

        $this->assertFalse($source->refresh()->active);
    }

    /**
     * Abre a tela como ADMIN e faz uma ação válida (desativa a origem), o que também prova que a
     * requisição montada aqui é aceita pelo endpoint quando o perfil é o certo.
     *
     * @return array{0: User, 1: KnowledgeSource, 2: string}
     */
    private function openSourcesPageAsAdmin(): array
    {
        $admin = User::factory()->admin()->create();
        $source = app(KnowledgeSourceService::class)->create([
            'name' => 'ANVISA',
            'collection' => 'Bulário Eletrônico',
            'type' => KnowledgeSource::TYPE_REGULATORY,
        ]);

        $page = $this->actingAs($admin)->get('/admin/knowledge/sources')->assertOk();
        $snapshot = Utils::extractAttributeDataFromHtml($page->getContent(), 'wire:snapshot');
        $this->assertSame('admin.knowledge.sources', $snapshot['memo']['name']);
        $this->assertSame('admin/knowledge/sources', $snapshot['memo']['path']);
        $snapshot = json_encode($snapshot);

        $response = $this->callToggleActive($admin, $snapshot, $source)->assertOk();
        $this->assertFalse($source->refresh()->active);

        return [$admin, $source, $response->json('components.0.snapshot')];
    }

    private function callToggleActive(User $actor, string $snapshot, KnowledgeSource $source): TestResponse
    {
        // Em produção cada requisição começa com o estado do Livewire limpo; no teste o processo é o mesmo.
        Livewire::flushState();

        return $this->actingAs($actor)
            ->withHeader('X-Livewire', '1')
            ->postJson(Livewire::getUpdateUri(), [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [['path' => '', 'method' => 'toggleActive', 'params' => [$source->id]]],
                ]],
            ]);
    }
}
