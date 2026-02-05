<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Coroutine\Coroutine;

use function Hypervel\Coroutine\run;

/**
 * Tests that must run outside coroutine context.
 *
 * These tests verify behavior when NOT already in a coroutine,
 * so we disable RunTestsInCoroutine's automatic coroutine wrapping.
 *
 * @internal
 * @coversNothing
 */
class CoroutineNonCoroutineContextTest extends CoroutineTestCase
{
    protected bool $enableCoroutine = false;

    public function testCoroutineInTopCoroutine(): void
    {
        run(function () {
            $this->assertSame(0, Coroutine::parentId());
        });
    }
}
