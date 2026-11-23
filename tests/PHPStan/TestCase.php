<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan;

use Hypervel\Tests\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Keep the analyzer application's exception handlers.
     *
     * The suite shares one booted application per process, and PHPUnit already
     * restores the handlers each test started with.
     */
    protected function flushExceptionHandlerState(): void
    {
    }
}
