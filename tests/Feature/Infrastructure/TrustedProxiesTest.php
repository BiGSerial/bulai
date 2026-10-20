<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class TrustedProxiesTest extends TestCase
{
    private ?string $previous = null;

    protected function tearDown(): void
    {
        $this->restoreEnvironment();
        parent::tearDown();
    }

    public function test_forwarded_headers_are_ignored_when_no_proxy_is_trusted(): void
    {
        $this->bootWithTrustedProxies(null);

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->getJson('/_probe', ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertJson(['ip' => '172.18.0.5', 'secure' => false]);
    }

    public function test_forwarded_headers_are_honoured_from_the_configured_proxy_network(): void
    {
        $this->bootWithTrustedProxies('172.18.0.0/16');

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->getJson('/_probe', ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->assertJson(['ip' => '203.0.113.9', 'secure' => true]);
    }

    public function test_forwarded_headers_are_ignored_from_a_host_outside_the_configured_network(): void
    {
        $this->bootWithTrustedProxies('172.18.0.0/16');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->getJson('/_probe', ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->assertJson(['ip' => '198.51.100.7', 'secure' => false]);
    }

    public function test_wildcard_trusts_any_proxy(): void
    {
        $this->bootWithTrustedProxies('*');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->getJson('/_probe', ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->assertJson(['ip' => '203.0.113.9', 'secure' => true]);
    }

    /** O middleware é configurado ao criar a aplicação; por isso o ambiente é definido antes de recriá-la. */
    private function bootWithTrustedProxies(?string $value): void
    {
        $this->previous = $_SERVER['TRUSTED_PROXIES'] ?? null;
        if ($value === null) {
            unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
            putenv('TRUSTED_PROXIES');
        } else {
            $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = $value;
            putenv('TRUSTED_PROXIES='.$value);
        }
        $this->refreshApplication();
        Route::get('/_probe', fn () => response()->json(['ip' => request()->ip(), 'secure' => request()->isSecure()]));
    }

    private function restoreEnvironment(): void
    {
        if ($this->previous === null) {
            unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
            putenv('TRUSTED_PROXIES');
        } else {
            $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = $this->previous;
            putenv('TRUSTED_PROXIES='.$this->previous);
        }
    }
}
