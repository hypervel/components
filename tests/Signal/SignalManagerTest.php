<?php

declare(strict_types=1);

namespace Hypervel\Tests\Signal;

use Hypervel\Config\Repository;
use Hypervel\Context\Context;
use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Signal\SignalHandlerInterface as SignalHandler;
use Hypervel\Signal\SignalManager;
use Hypervel\Tests\Signal\Stub\SignalHandler2Stub;
use Hypervel\Tests\Signal\Stub\SignalHandlerStub;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class SignalManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Context::set('test.signal', null);
    }

    public function testGetHandlers()
    {
        $container = $this->getContainer();
        $container->shouldReceive('make')->with(ConfigContract::class)->andReturnUsing(function () {
            return new Repository([
                'signal' => [
                    'handlers' => [
                        SignalHandlerStub::class,
                        SignalHandler2Stub::class => 1,
                    ],
                ],
            ]);
        });
        $manager = new SignalManager($container);
        $manager->init();

        $this->assertArrayHasKey(SignalHandler::WORKER, $manager->getHandlers());
        $this->assertArrayHasKey(SIGTERM, $manager->getHandlers()[SignalHandler::WORKER]);
        $this->assertIsArray($manager->getHandlers()[SignalHandler::WORKER]);
        $this->assertInstanceOf(SignalHandler2Stub::class, $manager->getHandlers()[SignalHandler::WORKER][SIGTERM][0]);
        $this->assertInstanceOf(SignalHandlerStub::class, $manager->getHandlers()[SignalHandler::WORKER][SIGTERM][1]);
    }

    public function testSetAndGetStopped()
    {
        $container = $this->getContainer();
        $container->shouldReceive('make')->with(ConfigContract::class)->andReturn(new Repository([]));

        $manager = new SignalManager($container);

        $this->assertFalse($manager->isStopped());

        $manager->setStopped(true);
        $this->assertTrue($manager->isStopped());

        $manager->setStopped(false);
        $this->assertFalse($manager->isStopped());
    }

    public function testInitWithNoHandlersConfigured()
    {
        $container = $this->getContainer();
        $container->shouldReceive('make')->with(ConfigContract::class)->andReturn(new Repository([]));

        $manager = new SignalManager($container);
        $manager->init();

        $this->assertEmpty($manager->getHandlers());
    }

    protected function getContainer()
    {
        $container = m::mock(ContainerContract::class);

        $container->shouldReceive('make')->with(SignalHandlerStub::class)->andReturnUsing(function () {
            return new SignalHandlerStub();
        });
        $container->shouldReceive('make')->with(SignalHandler2Stub::class)->andReturnUsing(function () {
            return new SignalHandler2Stub();
        });

        return $container;
    }
}
