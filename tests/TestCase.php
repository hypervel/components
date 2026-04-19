<?php

declare(strict_types=1);

namespace Hypervel\Tests;

use Hypervel\Foundation\Bootstrap\HandleExceptions;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use PHPUnit\Framework\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    use RunTestsInCoroutine;

    protected function tearDown(): void
    {
        HandleExceptions::flushState($this);
    }
}
