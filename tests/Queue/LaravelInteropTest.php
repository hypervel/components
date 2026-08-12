<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Bus\UniqueLock;
use Hypervel\Contracts\Database\ModelIdentifier;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\Middleware\WithoutOverlapping;
use Hypervel\Queue\Worker;
use Hypervel\Testbench\TestCase;
use ReflectionProperty;
use stdClass;

/**
 * Verify that queue cache keys, payload values, and class aliases match Laravel's
 * conventions so that Hypervel workers can process jobs dispatched by Laravel and
 * vice versa.
 *
 * If any of these tests fail, cross-framework queue interoperability is broken.
 * See QueueServiceProvider::registerLaravelInteropAliases() for context.
 */
class LaravelInteropTest extends TestCase
{
    public function testRestartSignalCacheKeyMatchesLaravel()
    {
        $this->assertSame('illuminate:queue:restart', Worker::RESTART_SIGNAL_CACHE_KEY);
    }

    public function testWithoutOverlappingPrefixMatchesLaravel()
    {
        $middleware = new WithoutOverlapping('test');

        $this->assertSame('laravel-queue-overlap:', $middleware->prefix);
    }

    public function testWithoutOverlappingDisplayNameKeyMatchesLaravel(): void
    {
        $job = new class {
            public function displayName(): string
            {
                return 'App\Jobs\InteropJob';
            }
        };

        $this->assertSame(
            'laravel-queue-overlap:' . hash('xxh128', 'App\Jobs\InteropJob') . ':test',
            (new WithoutOverlapping('test'))->getLockKey($job)
        );
    }

    // REMOVED: ThrottlesExceptions now uses the dedicated Hypervel rate-limiter
    // state format and no longer claims Laravel cache-key interoperability.

    public function testUniqueLockDisplayNameKeyMatchesLaravel(): void
    {
        $job = new class implements ShouldQueue, ShouldBeUnique {
            public function uniqueId(): string
            {
                return 'test-id';
            }

            public function displayName(): string
            {
                return 'App\Jobs\InteropJob';
            }
        };

        $this->assertSame(
            'laravel_unique_job:' . hash('xxh128', 'App\Jobs\InteropJob') . ':test-id',
            UniqueLock::getKey($job)
        );
    }

    public function testCallQueuedHandlerClassAliasIsRegistered()
    {
        $this->assertTrue(
            class_exists(\Illuminate\Queue\CallQueuedHandler::class),
            'Illuminate\Queue\CallQueuedHandler alias must be registered for Laravel job payload resolution.'
        );

        $this->assertInstanceOf(
            CallQueuedHandler::class,
            $this->app->make(\Illuminate\Queue\CallQueuedHandler::class)
        );
    }

    public function testCallQueuedHandlerInteropBindingsResolveFreshInstances(): void
    {
        $hypervelHandler = $this->app->make(CallQueuedHandler::class);
        $anotherHypervelHandler = $this->app->make(CallQueuedHandler::class);
        $laravelHandler = $this->app->make(\Illuminate\Queue\CallQueuedHandler::class);
        $anotherLaravelHandler = $this->app->make(\Illuminate\Queue\CallQueuedHandler::class);

        $this->assertNotSame($hypervelHandler, $anotherHypervelHandler);
        $this->assertNotSame($laravelHandler, $anotherLaravelHandler);
        $this->assertInstanceOf(CallQueuedHandler::class, $laravelHandler);

        $runningCommand = new stdClass;
        $property = new ReflectionProperty(CallQueuedHandler::class, 'runningCommand');
        $property->setValue($hypervelHandler, $runningCommand);

        $this->assertSame($runningCommand, $hypervelHandler->getRunningCommand());
        $this->assertNull($anotherHypervelHandler->getRunningCommand());
        $this->assertNull($laravelHandler->getRunningCommand());
        $this->assertNull($anotherLaravelHandler->getRunningCommand());
    }

    public function testModelIdentifierClassAliasIsRegistered()
    {
        $this->assertTrue(
            class_exists(\Illuminate\Contracts\Database\ModelIdentifier::class),
            'Illuminate\Contracts\Database\ModelIdentifier alias must be registered for Laravel model deserialization.'
        );

        $identifier = new ModelIdentifier(null, 1, [], null);

        $this->assertInstanceOf(
            \Illuminate\Contracts\Database\ModelIdentifier::class,
            $identifier
        );
    }
}
