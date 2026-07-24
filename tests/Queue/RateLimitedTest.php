<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\RateLimiter;
use Hypervel\Cache\RateLimiting\Limit;
use Hypervel\Cache\Repository;
use Hypervel\Container\Container;
use Hypervel\Queue\Middleware\RateLimited;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use stdClass;

enum RateLimitedTestStringEnum: string
{
    case Default = 'default';
}

enum RateLimitedTestIntEnum: int
{
    case Primary = 1;
}

enum RateLimitedTestUnitEnum
{
    case uploads;
}

class RateLimitedTest extends TestCase
{
    public function testConstructorAcceptsString(): void
    {
        $this->mockRateLimiter();

        new RateLimited('default');

        $this->assertTrue(true);
    }

    public function testConstructorAcceptsStringBackedEnum(): void
    {
        $this->mockRateLimiter();

        new RateLimited(RateLimitedTestStringEnum::Default);

        $this->assertTrue(true);
    }

    public function testConstructorAcceptsUnitEnum(): void
    {
        $this->mockRateLimiter();

        new RateLimited(RateLimitedTestUnitEnum::uploads);

        $this->assertTrue(true);
    }

    public function testConstructorAcceptsIntBackedEnum(): void
    {
        $this->mockRateLimiter();

        new RateLimited(RateLimitedTestIntEnum::Primary);

        $this->assertTrue(true);
    }

    public function testDontReleaseSetsShouldReleaseToFalse(): void
    {
        $this->mockRateLimiter();

        $middleware = new RateLimited('default');

        $this->assertTrue($middleware->shouldRelease);

        $result = $middleware->dontRelease();

        $this->assertFalse($middleware->shouldRelease);
        $this->assertSame($middleware, $result);
    }

    public function testNamedQueueLimiterUsesCentralizedScopedHash(): void
    {
        $limiter = new RateLimiter(new Repository(new ArrayStore));
        $limiter->for('uploads', fn () => Limit::perMinute(10)->by('user-1'));
        $limiter->resolveKeyScopeUsing(fn () => 'account-1');

        $container = new Container;
        $container->instance(RateLimiter::class, $limiter);
        Container::setInstance($container);

        $nextCalls = 0;
        (new RateLimited('uploads'))->handle(
            new stdClass,
            function () use (&$nextCalls): string {
                ++$nextCalls;

                return 'handled';
            },
        );

        $this->assertSame(1, $nextCalls);
        $this->assertSame(
            1,
            $limiter->attempts(hash('xxh128', 'account-1:uploadsuser-1')),
        );
    }

    public function testUnlimitedNamedQueueLimiterBypassesStorage(): void
    {
        $limiter = new RateLimiter(new Repository(new ArrayStore));
        $limiter->for('uploads', fn () => Limit::none());

        $container = new Container;
        $container->instance(RateLimiter::class, $limiter);
        Container::setInstance($container);

        $result = (new RateLimited('uploads'))->handle(
            new stdClass,
            fn (): string => 'handled',
        );

        $this->assertSame('handled', $result);
        $this->assertSame(0, $limiter->attempts(hash('xxh128', 'uploads')));
    }

    /**
     * Create a mock RateLimiter and set up the container.
     */
    protected function mockRateLimiter(): RateLimiter&MockInterface
    {
        $limiter = m::mock(RateLimiter::class);

        $container = new Container;
        $container->instance(RateLimiter::class, $limiter);

        Container::setInstance($container);

        return $limiter;
    }
}
