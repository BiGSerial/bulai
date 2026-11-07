<?php

namespace Tests\Feature;

use Tests\TestCase;

class PwaAssetTest extends TestCase
{
    public function test_manifest_meets_the_installability_criteria(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertSame('BULA-IA', $manifest['name']);
        $this->assertSame('BULA-IA', $manifest['short_name']);
        $this->assertSame('/', $manifest['id']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame(['standalone', 'minimal-ui'], $manifest['display_override']);
        $this->assertSame('/inicio?source=pwa', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertFalse($manifest['prefer_related_applications']);
        $this->assertSame('/brand/bulai-icone-app.svg', $manifest['icons'][0]['src']);

        // Chrome exige PNG 192 e 512 "any"; Android usa o maskable; iOS usa o apple-touch-icon do layout.
        $png = collect($manifest['icons'])->where('type', 'image/png');
        $this->assertSame(['192x192', '512x512', '512x512'], $png->pluck('sizes')->values()->all());
        $this->assertContains('maskable', $png->pluck('purpose')->all());
        $this->assertContains('any', $png->where('sizes', '512x512')->pluck('purpose')->all());
        foreach ($png->pluck('src')->unique() as $src) {
            $this->assertFileExists(public_path(ltrim($src, '/')));
            $this->assertSame('image/png', mime_content_type(public_path(ltrim($src, '/'))));
        }
        [$width] = getimagesize(public_path('brand/bulai-icone-512.png'));
        $this->assertSame(512, $width);

        foreach ($manifest['shortcuts'] as $shortcut) {
            $this->assertStringStartsWith('/', $shortcut['url']);
            $this->assertFileExists(public_path(ltrim($shortcut['icons'][0]['src'], '/')));
        }
    }

    public function test_layouts_declare_the_metas_for_ios_and_android_installation(): void
    {
        foreach (['layouts/app', 'welcome'] as $view) {
            $html = file_get_contents(resource_path("views/{$view}.blade.php"));
            $this->assertStringContainsString('rel="apple-touch-icon" href="/brand/bulai-icone-180.png"', $html);
            $this->assertStringContainsString('rel="manifest" href="/manifest.webmanifest"', $html);
            $this->assertStringContainsString('viewport-fit=cover', $html);
        }
        $app = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString('name="theme-color" content="#12344d" media="(prefers-color-scheme: light)"', $app);
        $this->assertStringContainsString('name="apple-mobile-web-app-status-bar-style" content="default"', $app);
        $this->assertStringContainsString('name="apple-mobile-web-app-capable" content="yes"', $app);
        $this->assertStringContainsString('name="application-name" content="BULA-IA"', $app);
        $this->assertFileExists(public_path('brand/bulai-icone-180.png'));
        $this->assertStringContainsString("'/brand/bulai-icone-192.png'", file_get_contents(public_path('sw.js')));
    }

    public function test_install_flow_captures_the_browser_prompt_and_explains_the_ios_steps(): void
    {
        $js = file_get_contents(resource_path('js/pwa.js'));
        foreach (["addEventListener('beforeinstallprompt'", 'event.preventDefault()', "addEventListener('appinstalled'", 'window.bulaiInstalar =', 'prompt.userChoice', "matchMedia('(display-mode: standalone)')", 'navigator.standalone', "CustomEvent('bulai-pode-instalar'", 'bulai-instalar-dispensado-em'] as $needle) {
            $this->assertStringContainsString($needle, $js, "resources/js/pwa.js deve conter '{$needle}'.");
        }
        $this->assertStringContainsString("import './pwa.js'", file_get_contents(resource_path('js/app.js')));

        $card = file_get_contents(resource_path('views/components/consulta/instalar.blade.php'));
        $this->assertStringContainsString('Tenha o BULA-IA na tela do seu celular', $card);
        $this->assertStringContainsString('window.bulaiInstalar()', $card);
        $this->assertStringContainsString('Adicionar à Tela de Início', $card);
        $this->assertStringContainsString('x-on:bulai-pode-instalar.window', $card);
        $this->assertStringContainsString('Agora não', $card);

        $shell = file_get_contents(resource_path('views/components/consulta/shell.blade.php'));
        $this->assertStringContainsString('Instalar o aplicativo', $shell);
        $this->assertStringContainsString('window.bulaiInstalarAgora()', $shell);
    }

    public function test_service_worker_is_installable_and_does_not_cache_authenticated_navigation_paths(): void
    {
        $serviceWorker = file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("const CACHE_NAME = 'bulai-static-v6'", $serviceWorker);
        $this->assertStringContainsString("addEventListener('fetch'", $serviceWorker, 'o Chrome só instala PWA com handler de fetch');
        $this->assertStringContainsString("'/offline'", $serviceWorker);
        $this->assertStringContainsString("url.pathname.startsWith('/admin')", $serviceWorker);
        $this->assertStringContainsString("url.pathname.startsWith('/login')", $serviceWorker);
        $this->assertStringContainsString("url.pathname.startsWith('/brand/')", $serviceWorker);
        $this->assertStringContainsString("url.pathname.startsWith('/consulta')", $serviceWorker);
        $this->assertStringContainsString("url.pathname.startsWith('/historico')", $serviceWorker);
        $this->assertStringContainsString("url.pathname.startsWith('/hoje')", $serviceWorker);
        $this->assertStringContainsString("url.pathname.startsWith('/avisos')", $serviceWorker);
        $this->assertStringContainsString("addEventListener('notificationclick'", $serviceWorker);
        $this->assertStringContainsString('event.respondWith(fetch(request).catch(', $serviceWorker);
        $this->assertStringNotContainsString('cache.put(request, copy)', explode("url.pathname.startsWith('/build/')", $serviceWorker)[0]);
        $this->assertStringNotContainsString('self.skipWaiting();'."\n".'});', explode("addEventListener('message'", $serviceWorker)[0]);
    }

    public function test_offline_page_is_public_and_self_contained(): void
    {
        $this->get('/offline')->assertOk()->assertSee('Você está sem conexão')->assertSee('Tentar novamente')->assertDontSee('/build/');
    }

    public function test_brand_assets_use_the_approved_logo_palette_and_wordmark(): void
    {
        $logo = file_get_contents(public_path('brand/bulai-logo-horizontal-transparente.svg'));
        $icon = file_get_contents(public_path('brand/bulai-icone-app.svg'));

        $this->assertStringContainsString('#12344D', $logo);
        $this->assertStringContainsString('#D9661F', $logo);
        $this->assertStringContainsString('Bulas Inteligentes', $logo);
        $this->assertStringContainsString('#12344D', $icon);
        $this->assertStringContainsString('#F0913F', $icon);
    }
}
