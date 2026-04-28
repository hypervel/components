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

    protected function setUp(): void
    {
        parent::setUp();

        set_error_handler(function () {
            $this->deprecationsFound = true;
        });
    }

    protected function tearDown(): void
    {
        $this->deprecationsFound = false;

        HandleExceptions::flushHandlersState($this);

        parent::tearDown();
    }

    public function testWithDeprecationHandling()
    {
        $this->withDeprecationHandling();

        trigger_error('Something is deprecated', E_USER_DEPRECATED);

        $this->assertTrue($this->deprecationsFound);
    }

    public function testWithoutDeprecationHandling()
    {
        $this->withoutDeprecationHandling();

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Something is deprecated');

        trigger_error('Something is deprecated', E_USER_DEPRECATED);
    }
}
