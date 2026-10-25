<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Fiscal do design system administrativo.
 *
 * Falha quando uma tela foge do padrão visual, de idioma ou de estrutura
 * descrito em docs/admin-design-system.md.
 */
class AdminDesignSystemTest extends TestCase
{
    private const FORBIDDEN_TERMS = [
        'Batch', 'Lote', 'Lotes', 'Chunk', 'Chunks', 'Embedding', 'Embeddings', 'Dashboard', 'Ingestão',
        'Fonte', 'Knowledge Admin', 'BULA-IA', 'Upload', 'Save', 'Cancel', 'Delete', 'Edit', 'Search',
        'Loading', 'Submit', 'Back', 'Next', 'Previous', 'Showing', 'results',
    ];

    private const ALLOWED_COLORS = ['slate', 'brand', 'emerald', 'amber', 'rose', 'sky', 'white', 'transparent', 'current'];

    private const ALLOWED_TEXT_SIZES = ['xs', 'sm', 'base', 'xl', '2xl', '3xl'];

    private const ALLOWED_ROUNDED = ['rounded', 'rounded-lg', 'rounded-xl', 'rounded-full'];

    private const ALLOWED_SHADOWS = ['shadow-sm', 'shadow-xl'];

    /**
     * @return array<string, string> caminho relativo => conteúdo
     */
    private function adminViews(): array
    {
        $files = [
            ...File::allFiles(resource_path('views/livewire/admin')),
            ...File::allFiles(resource_path('views/components/knowledge-admin')),
            ...File::allFiles(resource_path('views/vendor/livewire')),
            resource_path('views/auth/login.blade.php') => null,
            resource_path('views/auth/account.blade.php') => null,
            resource_path('views/auth/first-access.blade.php') => null,
        ];

        $views = [];
        foreach ($files as $key => $file) {
            $path = is_string($key) ? $key : $file->getPathname();
            $views[str_replace(base_path().'/', '', $path)] = File::get($path);
        }

        return $views;
    }

    public function test_pages_use_the_shared_shell_and_do_not_declare_their_own_title(): void
    {
        foreach (File::allFiles(resource_path('views/livewire/admin')) as $file) {
            $content = File::get($file->getPathname());

            $this->assertStringStartsWith('<x-knowledge-admin.shell', ltrim($content), "{$file->getFilename()} deve usar <x-knowledge-admin.shell>.");
            $this->assertStringNotContainsString('<h1', $content, "{$file->getFilename()}: o título da página vem do shell.");
        }
    }

    public function test_pages_use_form_and_button_components_instead_of_raw_controls(): void
    {
        foreach (File::allFiles(resource_path('views/livewire/admin')) as $file) {
            foreach (preg_split('/\R/', File::get($file->getPathname())) as $number => $line) {
                $this->assertDoesNotMatchRegularExpression('/<(select|textarea)\b/', $line, "{$file->getFilename()}:".($number + 1).' use <x-knowledge-admin.select|textarea>.');

                if (! str_contains($line, 'selectDocument(')) {
                    $this->assertDoesNotMatchRegularExpression('/<button\b/', $line, "{$file->getFilename()}:".($number + 1).' use <x-knowledge-admin.button>.');
                }

                if (preg_match('/<input\b/', $line)) {
                    $this->assertMatchesRegularExpression('/type="(file|checkbox)"/', $line, "{$file->getFilename()}:".($number + 1).' use <x-knowledge-admin.input>.');
                }
            }
        }
    }

    public function test_confirmations_use_the_confirm_modal_instead_of_the_native_browser_dialog(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            $this->assertStringNotContainsString('wire:confirm', File::get($file->getPathname()), "{$file->getRelativePathname()}: use <x-knowledge-admin.confirm-modal> em vez de wire:confirm (caixa nativa do navegador).");
        }
    }

    public function test_views_only_use_the_approved_palette_scale_and_shapes(): void
    {
        $colorPrefixes = 'bg|text|border|ring|divide|from|to|via|fill|stroke|outline|placeholder|accent|caret';

        foreach ($this->adminViews() as $path => $content) {
            $this->assertStringNotContainsString(' style="', $content, "{$path}: não use style inline.");
            $this->assertStringNotContainsString('<style', $content, "{$path}: não declare CSS na view.");

            preg_match_all('/(?<![\w-])((?:[a-z-]+:)*)(?:-?)((?:'.$colorPrefixes.')-([a-z]+)(?:-\d+)?(?:\/\d+)?)(?![\w-])/', $content, $colors);
            foreach ($colors[3] as $index => $color) {
                if (in_array($color, ['xs', 'sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl', 'left', 'right', 'center', 'wrap', 'nowrap', 'ellipsis', 'clip', 'none', 'opacity', 'collapse', 'separate', 'spacing', 'solid', 'dashed', 'dotted', 'double', 'x', 'y', 't', 'b', 'l', 'r', 's', 'e', 'inset', 'offset', 'current'], true)) {
                    continue;
                }

                $this->assertContains($color, self::ALLOWED_COLORS, "{$path}: cor '{$colors[2][$index]}' fora da paleta. Use slate, brand, emerald, amber, rose ou sky.");
            }

            preg_match_all('/(?<![\w-])(?:[a-z-]+:)*text-(xs|sm|base|lg|xl|[2-9]xl)(?![\w-])/', $content, $sizes);
            foreach ($sizes[1] as $size) {
                $this->assertContains($size, self::ALLOWED_TEXT_SIZES, "{$path}: tamanho de texto 'text-{$size}' fora da escala (".implode(', ', self::ALLOWED_TEXT_SIZES).').');
            }

            preg_match_all('/(?<![\w-])(?:[a-z-]+:)*(rounded(?:-[a-z0-9]+)?)(?![\w-])/', $content, $rounded);
            foreach ($rounded[1] as $class) {
                $this->assertContains($class, self::ALLOWED_ROUNDED, "{$path}: '{$class}' fora do padrão de cantos.");
            }

            preg_match_all('/(?<![\w-])(?:[a-z-]+:)*(shadow(?:-[a-z0-9]+)?)(?![\w-])/', $content, $shadows);
            foreach ($shadows[1] as $class) {
                $this->assertContains($class, self::ALLOWED_SHADOWS, "{$path}: '{$class}' fora do padrão de sombras.");
            }

            $this->assertDoesNotMatchRegularExpression('/(?<![\w-])(?:[a-z-]+:)*(?:text|bg|border|ring|p|px|py|m|mt|mb|w|h)-\[/', $content, "{$path}: valores arbitrários não são permitidos; use a escala do Tailwind.");
        }
    }

    public function test_user_facing_text_is_in_portuguese_and_uses_the_standard_vocabulary(): void
    {
        $sources = $this->adminViews();
        // O menu (rótulos e dicas) vem de App\AdminNavigation e aparece no shell e no primeiro acesso.
        foreach ([...File::allFiles(app_path('Livewire')), new \SplFileInfo(app_path('AdminNavigation.php'))] as $file) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", File::get($file->getPathname()), $strings);
            $sources[str_replace(base_path().'/', '', $file->getPathname())] = implode("\n", $strings[1]);
        }

        $pattern = '/(?<![\w$])('.implode('|', array_map('preg_quote', self::FORBIDDEN_TERMS)).')(?![\w\\\\])/';
        foreach ($sources as $path => $content) {
            $text = preg_replace(['/\{\{--.*?--\}\}/s', '/^\s*(use|namespace)\s.*$/m', '/@php.*?@endphp/s'], '', $content);
            $this->assertDoesNotMatchRegularExpression($pattern, $text, "{$path}: termo fora do vocabulário padrão (ver docs/admin-design-system.md).");
        }
    }

    public function test_page_titles_follow_the_standard_suffix(): void
    {
        foreach (File::allFiles(app_path('Livewire/Admin')) as $file) {
            preg_match_all('/->title\((.+)\);/', File::get($file->getPathname()), $titles);
            foreach ($titles[1] as $title) {
                $this->assertMatchesRegularExpression("/ · BULAI['\"]\$/", $title, "{$file->getFilename()}: título deve terminar com ' · BULAI'.");
            }
        }
    }
}
