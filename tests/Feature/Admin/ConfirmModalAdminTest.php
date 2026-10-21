<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Documents\Show as DocumentShow;
use App\Livewire\Admin\Knowledge\Upload;
use App\Livewire\Admin\Users\Index as UsersIndex;
use App\Livewire\Admin\Users\Show as UserShow;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Janelas de confirmação (<x-knowledge-admin.confirm-modal>) das ações de risco do admin.
 *
 * O comportamento no navegador (abrir, Esc, foco) depende de JavaScript e não é coberto aqui;
 * estes testes conferem o que o servidor entrega: texto, rótulo e a ação ligada ao botão.
 */
class ConfirmModalAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_detail_asks_for_confirmation_before_a_new_temporary_password_and_deactivation(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['name' => 'Bruno Lima']);

        $dialogs = $this->confirmDialogs(Livewire::actingAs($admin)->test(UserShow::class, ['user' => $user])->html());

        $this->assertSame(['Gerar nova senha provisória?', 'Desativar acesso?'], array_column($dialogs, 'title'));
        $this->assertSame(['Gerar nova senha', 'Desativar acesso'], array_column($dialogs, 'confirm'));
        $this->assertSame(['issueTemporaryPassword()', 'toggleActive()'], array_column($dialogs, 'action'));
        $this->assertStringContainsString('Bruno Lima', $dialogs[0]['message']);
        $this->assertStringContainsString('Bruno Lima', $dialogs[1]['message']);
    }

    public function test_users_list_gives_each_other_user_a_dialog_bound_to_that_user(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Ana Administradora']);
        $activeUser = User::factory()->create(['name' => 'Bruno Ativo']);
        $inactiveUser = User::factory()->inactive()->create(['name' => 'Carla Inativa']);

        $dialogs = $this->confirmDialogs(Livewire::actingAs($admin)->test(UsersIndex::class)->html());

        $this->assertSame(['Desativar acesso?', 'Reativar acesso?'], array_column($dialogs, 'title'));
        $this->assertSame(['Desativar acesso', 'Reativar acesso'], array_column($dialogs, 'confirm'));
        $this->assertSame(["toggleActive({$activeUser->id})", "toggleActive({$inactiveUser->id})"], array_column($dialogs, 'action'));
        $this->assertStringContainsString('Bruno Ativo', $dialogs[0]['message']);
        $this->assertStringContainsString('Carla Inativa', $dialogs[1]['message']);
    }

    public function test_document_detail_asks_for_confirmation_before_reprocessing_a_ready_document(): void
    {
        $admin = User::factory()->admin()->create();
        $source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'organization' => 'ANVISA']);
        $document = KnowledgeDocument::query()->create([
            'source_id' => $source->id,
            'external_key' => 'documento-pronto',
            'title' => 'Bula pronta',
            'document_type' => 'bula',
            'metadata' => [],
            'storage_path' => 'knowledge/originals/1/file.pdf',
            'content_status' => 'READY',
            'file_status' => 'READY',
            'embedding_status' => 'PENDING',
        ]);

        $dialogs = $this->confirmDialogs(Livewire::actingAs($admin)->test(DocumentShow::class, ['document' => $document])->html());

        $this->assertSame(['Reprocessar documento?'], array_column($dialogs, 'title'));
        $this->assertSame(['Reprocessar'], array_column($dialogs, 'confirm'));
        $this->assertSame(['processDocument(true)'], array_column($dialogs, 'action'));
    }

    public function test_upload_asks_for_confirmation_before_clearing_the_selection(): void
    {
        $admin = User::factory()->admin()->create();
        $files = [
            UploadedFile::fake()->createWithContent('dipirona.pdf', "%PDF-1.4\na\n%%EOF"),
            UploadedFile::fake()->createWithContent('paracetamol.pdf', "%PDF-1.4\nb\n%%EOF"),
        ];

        $dialogs = $this->confirmDialogs(Livewire::actingAs($admin)->test(Upload::class)->set('files', $files)->html());

        $this->assertSame(['Limpar seleção?'], array_column($dialogs, 'title'));
        $this->assertSame(['Limpar seleção'], array_column($dialogs, 'confirm'));
        $this->assertSame(['clearFiles()'], array_column($dialogs, 'action'));
        $this->assertStringContainsString('Os 2 arquivos selecionados', $dialogs[0]['message']);
    }

    /**
     * Lê cada janela como o leitor de tela: título e mensagem pelos ids de aria-labelledby e
     * aria-describedby (ids repetidos entre janelas trariam o texto de outra), mais o rótulo do
     * botão de confirmar (o último da janela) e a ação Livewire que ele chama.
     *
     * @return list<array{title: string, message: string, confirm: string, action: string}>
     */
    private function confirmDialogs(string $html): array
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        return array_values(array_map(function (Element $dialog) use ($document): array {
            $buttons = iterator_to_array($dialog->querySelectorAll('button'));
            $confirmButton = end($buttons);

            return [
                'title' => trim($document->getElementById($dialog->getAttribute('aria-labelledby'))->textContent),
                'message' => trim($document->getElementById($dialog->getAttribute('aria-describedby'))->textContent),
                'confirm' => trim(($confirmButton->querySelector('span') ?? $confirmButton)->textContent),
                'action' => Str::of($confirmButton->getAttribute('x-on:click'))->after('$wire.')->beforeLast(')')->toString(),
            ];
        }, iterator_to_array($document->querySelectorAll('[role="dialog"][aria-labelledby]'))));
    }
}
