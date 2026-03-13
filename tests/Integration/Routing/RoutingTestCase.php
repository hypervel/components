<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing;

use Hypervel\Testbench\TestCase;

/**
 * Base test case for all integration routing tests.
 *
 * The routing package stores per-request state in Context (__routing.parameters,
 * __routing.original_parameters, __routing.controller.*, etc.). Without coroutine
 * isolation, tests that trigger route matching or dispatch pollute the static
 * $nonCoContext, causing cross-test contamination when the suite runs together.
 *
 * @internal
 * @coversNothing
 */
abstract class RoutingTestCase extends TestCase
{
}
