<?php

declare(strict_types=1);

namespace Hypervel\Tests\Context;

use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class ApplicationContextTest extends TestCase
{
    public function testApplicationContext()
    {
        $container = m::mock(ContainerContract::class);
        ApplicationContext::setContainer($container);
        $this->assertSame($container, ApplicationContext::getContainer());
    }
}
