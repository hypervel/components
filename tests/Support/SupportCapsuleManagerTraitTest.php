<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Support\Fluent;
use Hypervel\Support\Traits\CapsuleManagerTrait;
use Hypervel\Tests\TestCase;
use ReflectionClass;

class SupportCapsuleManagerTraitTest extends TestCase
{
    use CapsuleManagerTrait;

    public function testSetupContainerForCapsule(): void
    {
        $app = new Container;

        $this->setupContainer($app);
        $this->assertSame($app, $this->getContainer());
        $this->assertInstanceOf(Fluent::class, $app->make('config'));
    }

    public function testSetupContainerForCapsuleWhenConfigIsBound(): void
    {
        $app = new Container;
        $app->instance('config', new Repository([]));

        $this->setupContainer($app);
        $this->assertSame($app, $this->getContainer());
        $this->assertInstanceOf(Repository::class, $app->make('config'));
    }

    public function testFlushStateClearsGlobalInstance()
    {
        $this->setAsGlobal();
        $this->assertSame($this, $this->getStaticInstance());

        static::flushState();

        $this->assertNull($this->getStaticInstance());
    }

    private function getStaticInstance(): ?object
    {
        return (new ReflectionClass(static::class))->getStaticPropertyValue('instance');
    }
}
