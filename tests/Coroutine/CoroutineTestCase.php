<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for coroutine package tests.
 *
 * Uses RunTestsInCoroutine trait so test methods automatically run in
 * a coroutine context - no need for explicit run() wrapping.
 *
 * @internal
 * @coversNothing
 */
abstract class CoroutineTestCase extends TestCase
{
    use RunTestsInCoroutine;

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
