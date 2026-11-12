<?php

namespace Tests\Unit\Services\Audit;

use App\Services\Audit\AuditSection;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class AuditSectionTest extends TestCase
{
    public function test_a_lost_connection_is_retried_once_and_the_block_succeeds(): void
    {
        $calls = 0;

        $result = AuditSection::run(function () use (&$calls): array {
            if (++$calls === 1) {
                throw new PDOException('SQLSTATE[HY000]: General error: 7 no connection to the server');
            }

            return ['ok' => true];
        });

        $this->assertSame(['ok' => true], $result);
        $this->assertSame(2, $calls);
    }

    public function test_a_lost_connection_that_persists_becomes_an_error_block(): void
    {
        $calls = 0;

        $result = AuditSection::run(function () use (&$calls): never {
            $calls++;
            throw new PDOException('SQLSTATE[HY000]: General error: 7 no connection to the server');
        });

        $this->assertTrue(AuditSection::failed($result));
        $this->assertSame('PDOException', $result['error']['exception']);
        $this->assertSame(2, $calls);
    }

    public function test_other_failures_are_not_retried(): void
    {
        $calls = 0;

        $result = AuditSection::run(function () use (&$calls): never {
            $calls++;
            throw new RuntimeException('formato inesperado');
        });

        $this->assertTrue(AuditSection::failed($result));
        $this->assertSame(1, $calls);
    }
}
