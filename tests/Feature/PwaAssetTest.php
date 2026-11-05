<?php

namespace Tests\Feature;

use Tests\TestCase;

class PwaAssetTest extends TestCase
{
    public function test_manifest_declares_standalone_pwa_shell(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertSame('BULA-IA', $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/inicio', $manifest['start_url']);
        $this->assertSame('/brand/bulai-icone-app.svg', $manifest['icons'][0]['src']);

        // iOS e parte dos Android exigem PNG 192/512 (any + maskable) para instalação com ícone correto.
        $png = collect($manifest['icons'])->where('type', 'image/png');
        $this->assertSame(['192x192', '512x512', '512x512'], $png->pluck('sizes')->values()->all());
        $this->assertContains('maskable', $png->pluck('purpose')->all());
        foreach ($png->pluck('src')->unique() as $src) {
            $this->assertFileExists(public_path(ltrim($src, '/')));
            $this->assertSame('image/png', mime_content_type(public_path(ltrim($src, '/'))));
        }
        [$width] = getimagesize(public_path('brand/bulai-icone-512.png'));
        $this->assertSame(512, $width);
    }

    public function test_layouts_link_the_apple_touch_icon_for_ios_installation(): void
    {
        foreach (['layouts/app', 'welcome'] as $view) {
            $html = file_get_contents(resource_path("views/{$view}.blade.php"));
            $this->assertStringContainsString('rel="apple-touch-icon" href="/brand/bulai-icone-180.png"', $html);
        }
        $this->assertFileExists(public_path('brand/bulai-icone-180.png'));
        $this->assertStringContainsString("'/brand/bulai-icone-192.png'", file_get_contents(public_path('sw.js')));
    }

    public function test_service_worker_does_not_cache_authenticated_navigation_paths(): void
    {
        $serviceWorker = file_get_contents(public_path('sw.js'));

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
