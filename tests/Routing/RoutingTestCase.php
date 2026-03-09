<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing;

use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;

/**
 * Base test case for all routing package tests.
 *
 * The routing package stores per-request state in Context (__routing.parameters,
 * __routing.original_parameters, __routing.controller.*, etc.). Without coroutine
 * isolation, any test calling match(), bind(), or dispatch() pollutes the static
 * $nonCoContext, causing cross-test contamination when the suite runs together.
 *
 * @internal
 * @coversNothing
 */
abstract class RoutingTestCase extends TestCase
{
    use RunTestsInCoroutine;
}
