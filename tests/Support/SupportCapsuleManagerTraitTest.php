<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
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
        $config = $app->make('config');
        $this->assertInstanceOf(Repository::class, $config);
        $config->set('queue.default', 'default');
        $this->assertSame('default', $config->string('queue.default'));
    }

    public function testSetupContainerForCapsuleWhenConfigIsBound(): void
    {
        $app = new Container;
        $app->instance('config', $config = new Repository([]));

        $this->setupContainer($app);
        $this->assertSame($app, $this->getContainer());
        $this->assertSame($config, $app->make('config'));
    }

    public function testFlushStateClearsGlobalInstance(): void
    {
        $this->setAsGlobal();
        $this->assertSame($this, $this->getStaticInstance());

        static::flushState();

        $this->assertNull($this->getStaticInstance());
    }

    /**
     * Get the globally selected Capsule instance.
     */
    private function getStaticInstance(): ?object
    {
        return (new ReflectionClass(static::class))->getStaticPropertyValue('instance');
    }
}
