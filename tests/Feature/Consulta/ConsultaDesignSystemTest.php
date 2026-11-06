<?php

namespace Tests\Feature\Consulta;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Fiscal de layout mobile e vocabulário do app do paciente (regras em docs/design/principios-de-ux.md, seção 9).
 *
 * Falha quando uma tela usa uma classe que, a 360–400 px de largura, empurra o conteúdo para fora da tela
 * (rolagem horizontal) ou quando expõe jargão técnico ao paciente. Um elemento que precisa de uma exceção
 * consciente leva o atributo `data-mobile-ok="motivo"` na mesma linha.
 */
final class ConsultaDesignSystemTest extends TestCase
{
    private const FORBIDDEN_TERMS = ['chunk', 'chunks', 'OCR', 'embedding', 'embeddings', 'LLM', 'token', 'tokens', 'tsvector', 'pgvector', 'prompt'];

    /** Trechos permitidos fora da regra geral, por arquivo (barra inferior de 3 destinos). */
    private const ALLOWED_SNIPPETS = [
        'resources/views/components/consulta/shell.blade.php' => ['grid max-w-3xl grid-cols-3'],
    ];

    /** @return array<string, string> caminho relativo => conteúdo */
    private function patientViews(): array
    {
        $files = [...File::allFiles(resource_path('views/livewire/consulta')), ...File::allFiles(resource_path('views/components/consulta')), new \SplFileInfo(resource_path('views/welcome.blade.php'))];
        $views = [];
        foreach ($files as $file) {
            $views[str_replace(base_path().'/', '', $file->getPathname())] = File::get($file->getPathname());
        }

        return $views;
    }

    /** @return list<array{line: int, text: string}> linhas sem comentários Blade */
    private function lines(string $content): array
    {
        $lines = [];
        foreach (preg_split('/\R/', preg_replace('/\{\{--.*?--\}\}/s', '', $content)) as $index => $text) {
            $lines[] = ['line' => $index + 1, 'text' => $text];
        }

        return $lines;
    }

    private function allowed(string $path, string $line): bool
    {
        if (str_contains($line, 'data-mobile-ok=')) {
            return true;
        }
        foreach (self::ALLOWED_SNIPPETS[$path] ?? [] as $snippet) {
            if (str_contains($line, $snippet)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> atributos class="…" da linha */
    private function classAttributes(string $line): array
    {
        preg_match_all('/\bclass="([^"]*)"/', $line, $matches);

        return $matches[1];
    }

    public function test_patient_views_never_force_a_width_the_phone_cannot_show(): void
    {
        foreach ($this->patientViews() as $path => $content) {
            foreach ($this->lines($content) as ['line' => $number, 'text' => $text]) {
                $where = "{$path}:{$number}";
                if ($this->allowed($path, $text)) {
                    continue;
                }
                $this->assertStringNotContainsString(' style="', $text, "{$where}: não use style inline; use classes (o fiscal não consegue conferir larguras em CSS inline).");

                foreach ($this->classAttributes($text) as $classes) {
                    if (str_contains($classes, 'whitespace-nowrap')) {
                        $this->assertMatchesRegularExpression('/\b(truncate|overflow-hidden|overflow-x-auto)\b/', $classes, "{$where}: 'whitespace-nowrap' sem 'truncate'/'overflow-hidden' estoura a largura no celular.");
                    }
                    $this->assertDoesNotMatchRegularExpression('/(?<![\w:-])grid-cols-([3-9]|1[0-2])(?![\w-])/', $classes, "{$where}: 'grid-cols-3' ou mais sem prefixo responsivo (use grid-cols-1 sm:grid-cols-3, ou data-mobile-ok=\"motivo\" para um seletor de opções curtas).");
                    if (preg_match('/(?<![\w:-])(?:w-(?:64|72|80|96|\[[^\]]+\])|min-w-\[[^\]]+\]|min-w-(?:64|72|80|96))(?![\w-])/', $classes) === 1) {
                        $this->assertMatchesRegularExpression('/(?<![\w:-])max-w-/', $classes, "{$where}: largura fixa (w-64+, w-[…], min-w-[…]) sem 'max-w-…' pode passar da tela de 360 px.");
                    }
                    if (preg_match_all('/text-\[(\d+(?:\.\d+)?)(rem|px)\]/', $classes, $sizes, PREG_SET_ORDER) > 0) {
                        foreach ($sizes as [$all, $value, $unit]) {
                            $rem = $unit === 'px' ? (float) $value / 16 : (float) $value;
                            if ($rem > 2) {
                                $this->assertMatchesRegularExpression('/<h[12]\b/', $text, "{$where}: '{$all}' maior que 2rem só em <h1>/<h2>.");
                            }
                        }
                    }
                    if (str_contains($classes, 'fixed') && str_contains($classes, 'inset-x-0')) {
                        $after = substr($content, (int) strpos($content, $classes), 600);
                        $this->assertMatchesRegularExpression('/max-w-/', $after, "{$where}: elemento fixo de borda a borda precisa de um filho com 'max-w-…' (mx-auto) para não esticar no tablet.");
                    }
                }
            }

            foreach (array_keys(preg_split('/<table\b/', $content)) as $index) {
                if ($index === 0) {
                    continue;
                }
                $position = $this->nthOccurrence($content, '<table', $index);
                $before = substr($content, max(0, $position - 600), min(600, $position));
                $this->assertStringContainsString('overflow-x-auto', $before, "{$path}: <table> fora de um contêiner com 'overflow-x-auto'.");
            }
        }
    }

    public function test_patient_views_use_the_shared_shell_and_the_app_screen_guards(): void
    {
        foreach (File::allFiles(resource_path('views/livewire/consulta')) as $file) {
            if (in_array($file->getFilename(), ['sino-de-avisos.blade.php'], true)) {
                continue;
            }
            $this->assertStringStartsWith('<x-consulta.shell', ltrim(preg_replace(['/\{\{--.*?--\}\}/s', '/@php.*?@endphp/s'], '', File::get($file->getPathname()))), "{$file->getRelativePathname()} deve usar <x-consulta.shell>.");
        }

        $shell = File::get(resource_path('views/components/consulta/shell.blade.php'));
        $this->assertStringContainsString('class="app-screen', $shell);
        $this->assertStringContainsString('env(safe-area-inset-bottom', $shell);
        $this->assertStringContainsString('x-on:bulai-navegando.window="menu = false"', $shell);

        $css = File::get(resource_path('css/app.css'));
        foreach (['overscroll-behavior-y: none', 'touch-action: manipulation', '-webkit-tap-highlight-color: transparent', '@view-transition', 'prefers-reduced-motion', 'min-height: 100dvh', 'overflow-x: clip', 'overflow-wrap: anywhere', 'env(safe-area-inset-top', 'display-mode: standalone'] as $rule) {
            $this->assertStringContainsString($rule, $css, "resources/css/app.css deve conter '{$rule}'.");
        }
    }

    public function test_patient_views_do_not_expose_technical_jargon(): void
    {
        $pattern = '/(?<![\w-])('.implode('|', array_map('preg_quote', self::FORBIDDEN_TERMS)).')(?![\w-])/i';
        foreach ($this->patientViews() as $path => $content) {
            $text = preg_replace(['/\{\{--.*?--\}\}/s', '/@php.*?@endphp/s', '/\{\{.*?\}\}/s', '/<[^>]+>/'], ' ', $content);
            $this->assertDoesNotMatchRegularExpression($pattern, $text, "{$path}: jargão técnico visível ao paciente (".implode(', ', self::FORBIDDEN_TERMS).').');
        }
    }

    private function nthOccurrence(string $haystack, string $needle, int $n): int
    {
        $offset = 0;
        for ($i = 0; $i < $n; $i++) {
            $position = strpos($haystack, $needle, $offset);
            if ($position === false) {
                return $offset;
            }
            $offset = $position + strlen($needle);
        }

        return $offset - strlen($needle);
    }
}
