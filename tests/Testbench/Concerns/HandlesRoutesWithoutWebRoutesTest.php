<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Testbench\TestCase;

/**
 * Tests routing when only defineRoutes() is overridden (not defineWebRoutes()).
 *
 * @internal
 * @coversNothing
 */
class HandlesRoutesWithoutWebRoutesTest extends TestCase
{
    use RunTestsInCoroutine;

    protected function defineRoutes($router): void
    {
        $router->get('/only-api', fn () => 'only_api_response');
    }

    public function testRoutesWorkWithoutDefineWebRoutes(): void
    {
        $this->get('/only-api')->assertSuccessful()->assertContent('only_api_response');
    }
}
