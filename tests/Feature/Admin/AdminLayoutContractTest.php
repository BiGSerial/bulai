<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Knowledge\UnansweredQuestions\Index as UnansweredIndex;
use App\Models\Consultation\MedicationQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Contrato de layout da administração (sintomas vistos em monitor largo e no celular): conteúdo em largura total, texto de
 * célula que quebra em vez de vazar, cabeçalho de tabela que não quebra por letra, barra lateral sem rolagem horizontal,
 * contraste de fundo e telas reativas. É um contrato de classes (não substitui a conferência visual no navegador).
 */
final class AdminLayoutContractTest extends TestCase
{
    use RefreshDatabase;

    private function shell(): string
    {
        return File::get(resource_path('views/components/knowledge-admin/shell.blade.php'));
    }

    public function test_the_content_uses_the_full_width_of_large_monitors_and_the_title_and_subtitle_wrap(): void
    {
        $shell = $this->shell();
        $this->assertStringNotContainsString('max-w-7xl', $shell, 'o conteúdo não fica mais preso a 1280 px');
        $this->assertStringContainsString('max-w-none', $shell);
        $this->assertStringContainsString('xl:px-8', $shell);
        $this->assertStringNotContainsString('truncate text-2xl', $shell, 'o título quebra em vez de ser cortado');
        $this->assertMatchesRegularExpression('/<p class="mt-1 max-w-4xl[^"]*">\{\{ \$subtitle \}\}<\/p>/', $shell, 'o subtítulo quebra em vez de ser cortado');
    }

    public function test_the_page_has_a_contrasting_background_and_the_menu_never_scrolls_sideways(): void
    {
        $shell = $this->shell();
        $this->assertStringContainsString('bg-slate-200', $shell, 'fundo cinza-azulado: os cartões brancos se destacam');
        $this->assertStringNotContainsString('min-h-screen bg-surface-50', $shell);
        $nav = File::get(resource_path('views/components/knowledge-admin/sidebar-nav.blade.php'));
        $this->assertStringContainsString('overflow-x-hidden', $nav);
        $this->assertStringContainsString('overflow-y-auto', $nav);
        $link = File::get(resource_path('views/components/knowledge-admin/sidebar-link.blade.php'));
        $this->assertStringContainsString('admin-nav-desc hidden', $link, 'a descrição de segunda linha só aparece em janelas altas');
        $this->assertStringContainsString('min-height: 62rem', File::get(resource_path('css/app.css')));
    }

    public function test_table_cells_wrap_by_default_the_header_never_breaks_by_letter_and_mobile_keeps_the_first_column(): void
    {
        $table = File::get(resource_path('views/components/knowledge-admin/data-table.blade.php'));
        $this->assertStringNotContainsString('[&_td]:whitespace-nowrap', $table, 'o texto das células quebra (antes vazava sobre a coluna vizinha)');
        $this->assertStringContainsString('[&_td]:break-words', $table);
        $this->assertMatchesRegularExpression('/<th scope="col" class="whitespace-nowrap /', $table, 'cabeçalho nunca quebra por letra');
        $this->assertStringContainsString('bg-brand-700', $table, 'cabeçalho escuro de alto contraste');
        $this->assertStringContainsString('max-lg:[&_td:first-child]:sticky', $table, 'no celular a primeira coluna fica fixa');
    }

    public function test_no_admin_cell_combines_nowrap_with_a_width_cap_that_would_overflow_onto_the_next_column(): void
    {
        foreach (File::allFiles(resource_path('views/livewire/admin')) as $file) {
            foreach (preg_split('/\R/', File::get($file->getPathname())) as $number => $line) {
                if (preg_match('/<td class="([^"]*)"/', $line, $m) === 1 && str_contains($m[1], 'whitespace-nowrap') && preg_match('/max-w-(xs|sm|md|lg)/', $m[1]) === 1 && ! str_contains($line, 'truncate')) {
                    $this->fail("{$file->getRelativePathname()}:".($number + 1).': célula com whitespace-nowrap e max-w-* sem truncate (o texto vaza sobre a coluna vizinha).');
                }
            }
        }
        $this->assertTrue(true);
    }

    public function test_the_unanswered_questions_screen_has_status_tabs_with_counts_and_refreshes_itself(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        foreach ([['pending', 'no_evidence'], ['pending', 'no_leaflet'], ['resolved', 'no_evidence']] as $i => [$review, $reason]) {
            MedicationQuestion::query()->create([
                'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'medicine_name' => 'REMEDIO'.$i, 'medicines' => [], 'question' => 'Pergunta '.$i, 'status' => $reason,
                'evidences' => [], 'guard' => [], 'generation' => [], 'retrieval' => [], 'pipeline' => [], 'duration_ms' => 1, 'expires_at' => now()->addDay(), 'review_status' => $review, 'review_reason' => $reason,
            ]);
        }

        $page = Livewire::actingAs($admin)->test(UnansweredIndex::class)
            ->assertSee('Pendentes')->assertSee('Resolvidas')->assertSee('Descartadas')->assertSeeHtml('wire:poll.20s.visible')
            ->assertSee('REMEDIO0')->assertDontSee('REMEDIO2');
        $page->call('$set', 'status', 'resolved')->assertSee('REMEDIO2')->assertDontSee('REMEDIO0');
        $this->actingAs($admin)->get(route('admin.knowledge.unanswered.index'))->assertOk()->assertSee('bg-slate-200', false);
    }
}
