<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Sanctum\SanctumGuard;
use Hypervel\Tests\TestCase;

class SanctumGuardStaticStateTest extends TestCase
{
    public function testFlushStateClearsSanctumGuardMacros(): void
    {
        SanctumGuard::macro('staticStateProbe', static fn (): string => 'ok');

        $this->assertTrue(SanctumGuard::hasMacro('staticStateProbe'));

        SanctumGuard::flushState();

        $this->assertFalse(SanctumGuard::hasMacro('staticStateProbe'));
    }
}
