<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use Hypervel\Foundation\Bootstrap\HandleExceptions;
use Hypervel\Foundation\Testing\Concerns\InteractsWithEnvironment;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Testing\Concerns\InteractsWithMockery;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Throwable;

/**
 * Base test case for coroutine-aware tests that do not boot an application.
 *
 * Use Hypervel\Testbench\TestCase when a test needs an application.
 */
abstract class UnitTestCase extends BaseTestCase
{
    use InteractsWithEnvironment;
    use InteractsWithMockery;
    use RunTestsInCoroutine;

    /**
     * Clean up the testing environment after the test.
     */
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
            // Preserve the first cleanup failure while still running Mockery teardown.
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
