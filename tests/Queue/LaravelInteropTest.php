<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Bus\UniqueLock;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\ModelIdentifier;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\Middleware\ThrottlesExceptions;
use Hypervel\Queue\Middleware\WithoutOverlapping;
use Hypervel\Queue\Worker;
use Hypervel\Testbench\TestCase;
use ReflectionProperty;

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

    public function testThrottlesExceptionsPrefixMatchesLaravel()
    {
        $middleware = new ThrottlesExceptions;

        $reflection = new ReflectionProperty($middleware, 'prefix');

        $this->assertSame('laravel_throttles_exceptions:', $reflection->getValue($middleware));
    }

    public function testUniqueLockKeyPrefixMatchesLaravel()
    {
        $job = new class implements ShouldQueue, ShouldBeUnique {
            public function uniqueId(): string
            {
                return 'test-id';
            }
        };

        $key = UniqueLock::getKey($job);

        $this->assertStringStartsWith('laravel_unique_job:', $key);
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
