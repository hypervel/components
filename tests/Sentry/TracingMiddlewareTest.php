<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Sentry\Tracing\Middleware;
use ReflectionClass;

/**
 * @internal
 * @coversNothing
 */
class TracingMiddlewareTest extends SentryTestCase
{
    protected array $defaultSetupConfig = [
        'sentry.dsn' => 'https://key@sentry.io/123',
    ];

    public function testScopedRegistrationGivesDifferentInstancesPerCoroutine()
    {
        // Verify the middleware is registered as scoped
        $this->assertTrue($this->app->isScoped(Middleware::class));

        $instance1 = $this->app->make(Middleware::class);
        $instance2 = null;

        $channel = new \Swoole\Coroutine\Channel(1);

        \Hypervel\Coroutine\Coroutine::create(function () use (&$instance2, $channel) {
            $instance2 = $this->app->make(Middleware::class);
            $channel->push(true);
        });

        $channel->pop(1.0);

        $this->assertNotNull($instance2);
        $this->assertNotSame(
            $instance1,
            $instance2,
            'Scoped middleware should give different instances to different coroutines'
        );
    }

    public function testSameCoroutineGetsSameInstance()
    {
        $instance1 = $this->app->make(Middleware::class);
        $instance2 = $this->app->make(Middleware::class);

        $this->assertSame(
            $instance1,
            $instance2,
            'Same coroutine should get the same scoped middleware instance'
        );
    }

    public function testBootedTimestampIsStaticAndSharedAcrossInstances()
    {
        // Reset the static timestamp
        Middleware::setBootedTimestamp(1234567890.123);

        // Even though scoped gives different instances per coroutine,
        // the static bootedTimestamp should be visible from any instance
        $reflection = new ReflectionClass(Middleware::class);
        $property = $reflection->getProperty('bootedTimestamp');

        $this->assertSame(1234567890.123, $property->getValue());

        // Clean up
        Middleware::setBootedTimestamp(null);
        $property->setValue(null, null);
    }
}
