<?php

declare(strict_types=1);

namespace Hypervel\Tests\Guzzle;

use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for Guzzle unit tests.
 *
 * Uses RunTestsInCoroutine trait so test methods automatically run in
 * a coroutine context - required for CoroutineHandler which uses
 * Swoole's coroutine HTTP client.
 *
 * @internal
 * @coversNothing
 */
abstract class GuzzleTestCase extends TestCase
{
    use RunTestsInCoroutine;

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
