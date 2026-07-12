<?php

declare(strict_types=1);

namespace Hypervel\Tests;

use Hypervel\Foundation\Bootstrap\HandleExceptions;
use Hypervel\Foundation\Testing\Concerns\InteractsWithEnvironment;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Testing\Concerns\InteractsWithMockery;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Throwable;

class TestCase extends BaseTestCase
{
    use InteractsWithEnvironment;
    use InteractsWithMockery;
    use RunTestsInCoroutine;

    protected function tearDown(): void
    {
        $exception = null;

        try {
            $this->flushExceptionHandlerState();
        } catch (Throwable $throwable) {
            $exception = $throwable;
        }

        try {
            $this->tearDownTheTestEnvironmentUsingMockery();
        } catch (Throwable $throwable) {
            $exception ??= $throwable;
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Flush the global exception-handler state.
     */
    protected function flushExceptionHandlerState(): void
    {
        HandleExceptions::flushState($this);
    }
}
