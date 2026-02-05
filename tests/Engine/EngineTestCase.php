<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for engine unit tests.
 *
 * Uses RunTestsInCoroutine trait so test methods automatically run in
 * a coroutine context - no need for explicit runInCoroutine() wrapping.
 *
 * @internal
 * @coversNothing
 */
abstract class EngineTestCase extends TestCase
{
    use RunTestsInCoroutine;

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
