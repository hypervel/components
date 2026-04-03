<?php

declare(strict_types=1);

namespace Hypervel\Testbench\PHPUnit;

use Hypervel\Testbench\Concerns\HandlesAssertions;
use Hypervel\Testbench\Concerns\InteractsWithMockery;
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
        $this->tearDownTheTestEnvironmentUsingMockery();
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
