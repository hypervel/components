<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Support\Fluent;
use Hypervel\Support\Traits\CapsuleManagerTrait;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class SupportCapsuleManagerTraitTest extends TestCase
{
    use CapsuleManagerTrait;

    public function testSetupContainerForCapsule()
    {
        $app = new Container();

        $this->setupContainer($app);
        $this->assertEquals($app, $this->getContainer());
        $this->assertInstanceOf(Fluent::class, $app['config']);
    }

    public function testSetupContainerForCapsuleWhenConfigIsBound()
    {
        $app = new Container();
        $app['config'] = new Repository([]);

        $this->setupContainer($app);
        $this->assertEquals($app, $this->getContainer());
        $this->assertInstanceOf(Repository::class, $app['config']);
    }
}
