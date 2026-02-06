<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\Context\ApplicationContext;
use Hypervel\ObjectPool\SimpleObjectPool;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Container\ContainerInterface;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class SimpleObjectPoolTest extends TestCase
{
    public function testCreateObject()
    {
        $container = $this->getContainer();
        $object = new stdClass();
        $pool = new SimpleObjectPool($container, fn () => $object);

        $this->assertSame($object, $pool->get());
    }

    protected function getContainer()
    {
        $container = m::mock(ContainerInterface::class);
        ApplicationContext::setContainer($container);

        return $container;
    }
}
