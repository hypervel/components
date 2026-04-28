<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Hypervel\Routing\Router;
use Hypervel\Testbench\TestCase;

/**
 * Tests routing when only defineRoutes() is overridden (not defineWebRoutes()).
 */
class HandlesRoutesWithoutWebRoutesTest extends TestCase
{
    protected function defineRoutes(Router $router): void
    {
        $router->get('/only-api', fn () => 'only_api_response');
    }

    public function testRoutesWorkWithoutDefineWebRoutes(): void
    {
        $this->get('/only-api')->assertSuccessful()->assertContent('only_api_response');
    }
}
