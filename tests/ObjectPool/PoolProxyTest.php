<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\ObjectPool\Contracts\Factory;
use Hypervel\ObjectPool\Contracts\ObjectPool as ObjectPoolContract;
use Hypervel\ObjectPool\Lease;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\ObjectPool\PoolProxy;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

class PoolProxyTest extends TestCase
{
    protected Container $container;

    protected PoolManager $manager;

    protected PoolDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container;
        Container::setInstance($this->container);
        $this->manager = new PoolManager;
        $this->container->instance(Factory::class, $this->manager);
        $this->definition = new PoolDefinition(
            'test:auto:service:fingerprint',
            'service',
            'auto:fingerprint',
            PoolOptions::fromArray([]),
        );
    }

    public function testInvokeBorrowsConfiguresAndReleases(): void
    {
        $object = new PoolProxyObject;
        $released = [];
        $proxy = $this->proxy(
            static fn (): object => $object,
            function (object $releasedObject) use (&$released): void {
                $released[] = $releasedObject;
            },
            static function (object $configured): void {
                $configured->state = 'configured';
            },
        );

        $this->assertSame('configured:value', $proxy->handle('value'));

        $pool = $this->manager->get($this->definition->identity);
        $this->assertSame([$object], $released);
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
        $this->manager->flush();
    }

    public function testPoolIsResolvedPerOperationAfterInvalidation(): void
    {
        $created = 0;
        $proxy = $this->proxy(function () use (&$created): object {
            ++$created;

            return new PoolProxyObject;
        });

        $proxy->handle('first');
        $first = $this->manager->get($this->definition->identity);

        $this->assertTrue($proxy->invalidatePool());
        $this->assertTrue($first->isClosed());

        $proxy->handle('second');
        $second = $this->manager->get($this->definition->identity);

        $this->assertNotSame($first, $second);
        $this->assertSame(2, $created);
        $this->manager->flush();
    }

    public function testConfigureFailureDiscardsThePartiallyConfiguredObject(): void
    {
        $pool = $this->manager->getOrCreate(
            $this->definition,
            static fn (): object => new PoolProxyObject,
        );
        $failure = new RuntimeException('configuration failed');
        $proxy = $this->proxy(
            static fn (): never => throw new RuntimeException('existing pool factory must win'),
            configure: function () use ($failure): never {
                throw $failure;
            },
        );

        try {
            $proxy->handle('value');
            $this->fail('Expected configuration to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(0, $pool->getCurrentObjectNumber());
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->manager->flush();
    }

    public function testDiscardFailureDoesNotMaskAConfigureFailure(): void
    {
        $object = new PoolProxyObject;
        $configureFailure = new RuntimeException('configuration failed');
        $discardFailure = new RuntimeException('discard failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($discardFailure);
        $this->container->instance(ExceptionHandler::class, $handler);

        $pool = m::mock(ObjectPoolContract::class);
        $pool->shouldReceive('get')->once()->andReturn($object);
        $pool->shouldReceive('discard')->once()->with($object)->andThrow($discardFailure);
        $factory = m::mock(Factory::class);
        $factory->shouldReceive('getOrCreate')->once()->andReturn($pool);
        $proxy = new InspectablePoolProxy(
            $this->definition,
            static fn (): object => $object,
            $factory,
            configure: function () use ($configureFailure): never {
                throw $configureFailure;
            },
        );

        try {
            $proxy->handle('value');
            $this->fail('Expected configuration to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($configureFailure, $exception);
        }
    }

    public function testOperationFailureRemainsPrimaryWhenFinalizationAlsoFails(): void
    {
        $operationFailure = new RuntimeException('operation failed');
        $finalizationFailure = new RuntimeException('release failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($finalizationFailure);
        $this->container->instance(ExceptionHandler::class, $handler);
        $proxy = $this->proxy(
            static fn (): object => new PoolProxyObject($operationFailure),
            function () use ($finalizationFailure): never {
                throw $finalizationFailure;
            },
        );

        try {
            $proxy->fail();
            $this->fail('Expected the operation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($operationFailure, $exception);
        }

        $pool = $this->manager->get($this->definition->identity);
        $this->assertSame(0, $pool->getCurrentObjectNumber());
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->manager->flush();
    }

    public function testFinalizationFailurePropagatesAfterSuccessfulOperation(): void
    {
        $failure = new RuntimeException('release failed');
        $proxy = $this->proxy(
            static fn (): object => new PoolProxyObject,
            function () use ($failure): never {
                throw $failure;
            },
        );

        try {
            $proxy->handle('value');
            $this->fail('Expected finalization to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(0, $this->manager->get($this->definition->identity)->getCurrentObjectNumber());
        $this->manager->flush();
    }

    public function testReleaseCallbackTravelsWithSynchronousAndDeferredLeases(): void
    {
        $releaseCount = 0;
        $proxy = $this->proxy(
            static fn (): object => new PoolProxyObject,
            function () use (&$releaseCount): void {
                ++$releaseCount;
            },
        );

        $proxy->handle('value');
        $lease = $proxy->leaseForTest();
        $this->assertInstanceOf(PoolProxyObject::class, $lease->get());
        $lease->release();

        $this->assertSame(2, $releaseCount);
        $this->manager->flush();
    }

    public function testMetadataAndInvalidationDelegateToTheDefinitionAndFactory(): void
    {
        $proxy = $this->proxy(static fn (): object => new PoolProxyObject);

        $this->assertSame($this->definition, $proxy->getDefinition());
        $this->assertSame($this->definition->identity, $proxy->getPoolName());
        $this->assertFalse($proxy->invalidatePool());

        $proxy->handle('value');

        $this->assertTrue($proxy->invalidatePool());
        $this->assertFalse($this->manager->has($this->definition->identity));
    }

    public function testBaseProxyHasNoPublicMagicForwarding(): void
    {
        $proxy = new PoolProxy(
            $this->definition,
            static fn (): object => new PoolProxyObject,
            $this->manager,
        );

        $this->assertFalse(method_exists($proxy, '__call'));
    }

    private function proxy(
        Closure $resolver,
        ?Closure $releaseCallback = null,
        ?Closure $configure = null,
    ): InspectablePoolProxy {
        return new InspectablePoolProxy(
            $this->definition,
            $resolver,
            $this->manager,
            $releaseCallback,
            $configure,
        );
    }
}

class InspectablePoolProxy extends PoolProxy
{
    public function __construct(
        PoolDefinition $definition,
        Closure $resolver,
        Factory $pools,
        ?Closure $releaseCallback = null,
        protected ?Closure $configure = null,
    ) {
        parent::__construct($definition, $resolver, $pools, $releaseCallback);
    }

    public function handle(string $value): string
    {
        return $this->invoke('handle', [$value]);
    }

    public function fail(): never
    {
        $this->invoke('fail', []);

        throw new RuntimeException('The proxied failure unexpectedly returned.');
    }

    public function leaseForTest(): Lease
    {
        return $this->lease();
    }

    protected function configureBorrowed(object $object): void
    {
        if ($this->configure !== null) {
            ($this->configure)($object);
        }
    }
}

class PoolProxyObject
{
    public string $state = 'initial';

    public function __construct(
        protected ?RuntimeException $failure = null,
    ) {
    }

    public function handle(string $value): string
    {
        return $this->state . ':' . $value;
    }

    public function fail(): never
    {
        throw $this->failure ?? new RuntimeException('operation failed');
    }
}
