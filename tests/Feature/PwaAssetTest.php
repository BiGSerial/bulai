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
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('/icon.svg', $manifest['icons'][0]['src']);
    }

    public function test_service_worker_does_not_cache_authenticated_navigation_paths(): void
    {
        $serviceWorker = file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("url.pathname.startsWith('/admin')", $serviceWorker);
        $this->assertStringContainsString("url.pathname.startsWith('/login')", $serviceWorker);
        $this->assertStringContainsString('event.respondWith(fetch(request))', $serviceWorker);
    }
}
