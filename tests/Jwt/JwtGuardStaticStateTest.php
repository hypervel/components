<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt;

use Hypervel\Jwt\JwtGuard;
use Hypervel\Tests\TestCase;

class JwtGuardStaticStateTest extends TestCase
{
    public function testFlushStateClearsMacros()
    {
        JwtGuard::macro('testMacro', function () {
            return 'test';
        });

        $this->assertTrue(JwtGuard::hasMacro('testMacro'));

        JwtGuard::flushState();

        $this->assertFalse(JwtGuard::hasMacro('testMacro'));
    }
}
