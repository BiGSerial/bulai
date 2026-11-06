<?php

namespace Tests\Feature\Admin;

use App\AdminHelp;
use App\AdminNavigation;
use App\Livewire\Admin\GlobalSearch;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/** Shell do admin: trilha, ajuda contextual de toda tela e busca global (telas, documentos, catálogo, usuários). */
final class AdminHelpAndSearchTest extends TestCase
{
    use RefreshDatabase;

    /** Rotas sem tela própria (arquivos, fluxo de eventos, verificação de serviços, download). */
    private const ROUTES_WITHOUT_SCREEN = ['admin.knowledge.stream', 'admin.knowledge.services.health', 'admin.knowledge.items.file', 'admin.knowledge.items.download', 'admin.reports.download'];

    public function test_every_admin_screen_has_contextual_help_and_a_place_in_the_menu(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->getName())->filter(fn (?string $name): bool => $name !== null && str_starts_with($name, 'admin.'))->diff(self::ROUTES_WITHOUT_SCREEN)->values();
        $this->assertGreaterThan(25, $names->count());

        foreach ($names as $name) {
            $help = AdminHelp::for($name);
            $this->assertNotNull($help, "{$name} não tem ajuda contextual em App\\AdminHelp.");
            $this->assertSame(['title', 'purpose', 'when', 'steps', 'related'], array_keys($help), $name);
            $this->assertNotEmpty($help['steps'], $name);
            foreach ($help['related'] as $link) {
                $this->assertTrue(Route::has($link['route']), "{$name}: tela relacionada {$link['route']} não existe.");
            }
            $this->assertNotNull(AdminNavigation::find($name), "{$name} não pertence a nenhum item do menu (trilha).");
        }

        $this->assertNull(AdminHelp::for('consulta.inicio'));
        $this->assertNull(AdminHelp::for(null));
        $this->assertSame(['Adicionar documentos', 'Importações', 'Pendências', 'Documentos'], array_column(AdminHelp::workflow(), 'label'));
        foreach (AdminNavigation::items() as $item) {
            $this->assertNotSame('', $item['description'], $item['route'].' sem descrição curta para o menu.');
        }
    }

    public function test_the_shell_shows_the_breadcrumb_the_help_panel_and_the_global_search(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.knowledge.documents.index'))
            ->assertOk()
            ->assertSee('Trilha')->assertSee('Base de conhecimento')
            ->assertSee('Ajuda desta tela')->assertSee('Para que serve')->assertSee('Quando usar')->assertSee('Passo a passo')->assertSee('Telas relacionadas')
            ->assertSee('Todas as bulas e materiais já incorporados à base.')
            ->assertSee('Fluxo das bulas')
            ->assertSee('Buscar telas, documentos, medicamentos, usuários… (Ctrl+K)')
            ->assertSee('data-testid="global-search"', false)
            ->assertSee('content-start');
    }

    public function test_the_global_search_groups_pages_documents_medicines_and_users_and_is_admin_only(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Ana Admin', 'email' => 'ana@exemplo.test']);
        User::factory()->create(['name' => 'Carlos Paciente', 'email' => 'carlos@exemplo.test']);
        $source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'type' => 'document', 'active' => true]);
        KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => 'anvisa-losartana', 'title' => 'Losartana — Bula do paciente', 'document_type' => 'bula_paciente', 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'READY']);
        AnvisaMedicine::factory()->create(['product_name' => 'LOSARTANA POTASSICA', 'normalized_product_name' => 'losartana potassica', 'active_ingredient' => 'LOSARTANA POTASSICA', 'normalized_active_ingredient' => 'losartana potassica', 'registration_status' => 'Ativo']);

        $component = Livewire::actingAs($admin)->test(GlobalSearch::class);
        $component->set('q', 'l')->assertDontSee('Documentos');
        $component->set('q', 'losar')
            ->assertSee('Documentos')->assertSee('Losartana — Bula do paciente')
            ->assertSee('Catálogo ANVISA')->assertSee('LOSARTANA POTASSICA')
            ->assertDontSee('Usuários');
        $component->set('q', 'carlos')->assertSee('Usuários')->assertSee('Carlos Paciente')->assertSee('carlos@exemplo.test')->assertDontSee('Documentos');
        $component->set('q', 'pendên')->assertSee('Telas')->assertSee('Pendências')->assertSee('Arquivos que esperam sua decisão');
        $component->set('q', 'zzzz-nada')->assertSee('Nada encontrado para “zzzz-nada”.');

        Livewire::actingAs(User::factory()->create())->test(GlobalSearch::class)->assertForbidden();
    }
}
