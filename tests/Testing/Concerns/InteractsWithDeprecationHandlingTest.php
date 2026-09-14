<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\Concerns;

use ErrorException;
use Hypervel\Foundation\Bootstrap\HandleExceptions;
use Hypervel\Foundation\Testing\Concerns\InteractsWithDeprecationHandling;
use Hypervel\Tests\TestCase;

class InteractsWithDeprecationHandlingTest extends TestCase
{
    use InteractsWithDeprecationHandling;

    protected bool $deprecationsFound = false;

    /**
     * Install the test deprecation handler.
     */
    protected function setUp(): void
    {
        parent::setUp();

        set_error_handler(function (): void {
            $this->deprecationsFound = true;
        });
    }

    /**
     * Restore the test error handlers.
     */
    protected function tearDown(): void
    {
        $this->deprecationsFound = false;

        HandleExceptions::flushHandlersState($this);

        parent::tearDown();
    }

    public function testWithDeprecationHandling(): void
    {
        $this->withDeprecationHandling();

        trigger_error('Something is deprecated', E_USER_DEPRECATED);

        $this->assertTrue($this->deprecationsFound);
    }

    public function testWithoutDeprecationHandling(): void
    {
        $this->withoutDeprecationHandling();

        $this->expectExceptionObject(new ErrorException('Something is deprecated'));

        trigger_error('Something is deprecated', E_USER_DEPRECATED);
    }
}
