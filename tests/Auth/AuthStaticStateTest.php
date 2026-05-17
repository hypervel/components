<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\RequestGuard;
use Hypervel\Auth\SessionGuard;
use Hypervel\Auth\TokenGuard;
use Hypervel\Tests\TestCase;

class AuthStaticStateTest extends TestCase
{
    public function testRequestGuardFlushStateClearsMacros(): void
    {
        RequestGuard::macro('testingStaticStateProbe', static fn (): string => 'ok');

        $this->assertTrue(RequestGuard::hasMacro('testingStaticStateProbe'));

        RequestGuard::flushState();

        $this->assertFalse(RequestGuard::hasMacro('testingStaticStateProbe'));
    }

    public function testSessionGuardFlushStateClearsMacros(): void
    {
        SessionGuard::macro('testingStaticStateProbe', static fn (): string => 'ok');

        $this->assertTrue(SessionGuard::hasMacro('testingStaticStateProbe'));

        SessionGuard::flushState();

        $this->assertFalse(SessionGuard::hasMacro('testingStaticStateProbe'));
    }

    public function testTokenGuardFlushStateClearsMacros(): void
    {
        TokenGuard::macro('testingStaticStateProbe', static fn (): string => 'ok');

        $this->assertTrue(TokenGuard::hasMacro('testingStaticStateProbe'));

        TokenGuard::flushState();

        $this->assertFalse(TokenGuard::hasMacro('testingStaticStateProbe'));
    }
}
