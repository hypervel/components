<?php

declare(strict_types=1);

namespace Hypervel\Testbench\PHPUnit;

use Hypervel\Foundation\Bootstrap\HandleExceptions;
use Hypervel\Testbench\Concerns\HandlesAssertions;
use Hypervel\Testing\Concerns\InteractsWithMockery;
use Override;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
class TestCase extends \PHPUnit\Framework\TestCase
{
    use HandlesAssertions;
    use InteractsWithMockery;

    /**
     * Tear down the testing environment.
     */
    #[Override]
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

    /**
     * Transform an exception into a throwable for PHPUnit.
     */
    #[Override]
    protected function transformException(Throwable $error): Throwable
    {
        return $error;
    }
}
