<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Container\Container;
use Hypervel\Queue\Middleware\RateLimited;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\LimitResult;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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

enum RateLimitedTestStore: string
{
    case Redis = 'redis';
}

class RateLimitedTest extends TestCase
{
    public function testConstructorAcceptsStringsAndEnums(): void
    {
        $this->mockRateLimiter();

        $this->assertInstanceOf(RateLimited::class, new RateLimited('default'));
        $this->assertInstanceOf(RateLimited::class, new RateLimited(RateLimitedTestStringEnum::Default));
        $this->assertInstanceOf(RateLimited::class, new RateLimited(RateLimitedTestUnitEnum::uploads));
        $this->assertInstanceOf(RateLimited::class, new RateLimited(RateLimitedTestIntEnum::Primary));
    }

    public function testDontReleaseSetsShouldReleaseToFalse(): void
    {
        $this->mockRateLimiter();
        $middleware = new RateLimited('default');

        $this->assertTrue($middleware->shouldRelease);
        $this->assertSame($middleware, $middleware->dontRelease());
        $this->assertFalse($middleware->shouldRelease);
    }

    public function testNamedLimiterUsesItsRegisteredStoreAndLimiterName(): void
    {
        $policy = Limit::perMinute(10)->by('user-1');
        $store = m::mock(Limiter::class);
        $store->shouldReceive('consumeMany')
            ->once()
            ->with([$policy], 'uploads')
            ->andReturn([new LimitResult(true, 10, 9, 0, 60_000_000)]);

        $manager = $this->mockRateLimiter();
        $manager->shouldReceive('limiter')->with('uploads')->once()->andReturn(fn () => $policy);
        $manager->shouldReceive('limiterStore')->with('uploads')->once()->andReturn('redis');
        $manager->shouldReceive('store')->with('redis')->once()->andReturn($store);

        $nextCalls = 0;
        $result = (new RateLimited('uploads'))->handle(
            new stdClass,
            function () use (&$nextCalls): string {
                ++$nextCalls;

                return 'handled';
            },
        );

        $this->assertSame('handled', $result);
        $this->assertSame(1, $nextCalls);
    }

    public function testExplicitStoreOverridesTheNamedLimiterStoreAndSurvivesSerialization(): void
    {
        $policy = Limit::perMinute(10);
        $store = m::mock(Limiter::class);
        $store->shouldReceive('consumeMany')
            ->once()
            ->with([$policy], 'uploads')
            ->andReturn([new LimitResult(true, 10, 9, 0, 60_000_000)]);

        $manager = $this->mockRateLimiter();
        $manager->shouldReceive('limiter')->with('uploads')->once()->andReturn(fn () => $policy);
        $manager->shouldReceive('limiterStore')->never();
        $manager->shouldReceive('store')->with('redis')->once()->andReturn($store);

        $middleware = unserialize(serialize(
            (new RateLimited('uploads'))->store(RateLimitedTestStore::Redis)
        ));

        $this->assertInstanceOf(RateLimited::class, $middleware);
        $this->assertSame('handled', $middleware->handle(new stdClass, fn (): string => 'handled'));
    }

    #[DataProvider('retryDelayProvider')]
    public function testDeniedJobUsesConfiguredRetryDelayAfterSerialization(
        int $retryMicroseconds,
        ?int $padding,
        ?int $fixedDelay,
        int $expectedDelay,
    ): void {
        $policy = Limit::perMinute(1);
        $store = m::mock(Limiter::class);
        $store->shouldReceive('consumeMany')
            ->once()
            ->with([$policy], 'uploads')
            ->andReturn([new LimitResult(false, 1, 0, $retryMicroseconds, $retryMicroseconds)]);

        $manager = $this->mockRateLimiter();
        $manager->shouldReceive('limiter')->andReturn(fn () => $policy);
        $manager->shouldReceive('limiterStore')->andReturnNull();
        $manager->shouldReceive('store')->with(null)->andReturn($store);

        $job = m::mock();
        $job->shouldReceive('release')->once()->with($expectedDelay)->andReturnNull();

        $middleware = new RateLimited('uploads');

        if ($padding !== null) {
            $this->assertSame($middleware, $middleware->releasePadding($padding));
        }

        if ($fixedDelay !== null) {
            $middleware->releaseAfter($fixedDelay);
        }

        $middleware = unserialize(serialize($middleware));

        $this->assertNull($middleware->handle($job, fn () => 'handled'));
    }

    public static function retryDelayProvider(): array
    {
        return [
            'default padding' => [7_000_000, null, null, 10],
            'custom padding' => [7_000_000, 5, null, 12],
            'zero padding preserves rounding' => [100_000, 0, null, 1],
            'zero padding preserves long delays' => [3_600_000_000, 0, null, 3600],
            'fixed delay overrides padding' => [7_000_000, 5, 60, 60],
            'fixed zero overrides padding' => [7_000_000, 5, 0, 0],
        ];
    }

    public function testNegativeReleasePaddingIsRejected(): void
    {
        $this->mockRateLimiter();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Release padding must not be negative.');

        (new RateLimited('uploads'))->releasePadding(-1);
    }

    public function testGroupUsesTheFirstDeniedPolicyToReleaseTheJob(): void
    {
        $first = Limit::perMinute(2)->by('first');
        $second = Limit::perMinute(1)->by('second');
        $store = m::mock(Limiter::class);
        $store->shouldReceive('consumeMany')
            ->once()
            ->with([$first, $second], 'uploads')
            ->andReturn([
                new LimitResult(true, 2, 2, 0, 0),
                new LimitResult(false, 1, 0, 60_000_000, 60_000_000),
            ]);

        $manager = $this->mockRateLimiter();
        $manager->shouldReceive('limiter')->andReturn(fn () => [$first, $second]);
        $manager->shouldReceive('limiterStore')->andReturnNull();
        $manager->shouldReceive('store')->andReturn($store);

        $job = m::mock();
        $job->shouldReceive('release')->once()->with(63)->andReturnNull();

        $nextCalls = 0;
        (new RateLimited('uploads'))->handle($job, function () use (&$nextCalls): void {
            ++$nextCalls;
        });

        $this->assertSame(0, $nextCalls);
    }

    public function testUnlimitedNamedQueueLimiterBypassesStorage(): void
    {
        $manager = $this->mockRateLimiter();
        $manager->shouldReceive('limiter')->with('uploads')->once()->andReturn(fn () => Limit::none());
        $manager->shouldReceive('store')->never();

        $result = (new RateLimited('uploads'))->handle(
            new stdClass,
            fn (): string => 'handled',
        );

        $this->assertSame('handled', $result);
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
