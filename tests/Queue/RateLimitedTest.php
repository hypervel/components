<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Container\Container;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\Middleware\RateLimited;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\LimitResult;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
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
    use VerifiesDoubles;

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
        $store = Double::for(Limiter::class)->strict();
        $store->expects('consumeMany')
            ->with([$policy], 'uploads')
            ->returns([new LimitResult(true, 10, 9, 0, 60_000_000)]);

        $manager = $this->mockRateLimiter();
        $manager->expects('limiter')->with('uploads')->returns(fn () => $policy);
        $manager->expects('limiterStore')->with('uploads')->returns('redis');
        $manager->expects('store')->with('redis')->returns($store);

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
        $store = Double::for(Limiter::class)->strict();
        $store->expects('consumeMany')
            ->with([$policy], 'uploads')
            ->returns([new LimitResult(true, 10, 9, 0, 60_000_000)]);

        $manager = $this->mockRateLimiter();
        $manager->expects('limiter')->with('uploads')->returns(fn () => $policy);
        $manager->expects('limiterStore')->never();
        $manager->expects('store')->with('redis')->returns($store);

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
        $store = Double::for(Limiter::class)->strict();
        $store->expects('consumeMany')
            ->with([$policy], 'uploads')
            ->returns([new LimitResult(false, 1, 0, $retryMicroseconds, $retryMicroseconds)]);

        $manager = $this->mockRateLimiter();
        $manager->allows('limiter')->returns(fn () => $policy);
        $manager->allows('limiterStore')->returns(null);
        $manager->allows('store')->with(null)->returns($store);

        $job = Double::for(RateLimitedTestJob::class)->strict();
        $job->expects('release')->with($expectedDelay)->returns(null);

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
        $store = Double::for(Limiter::class)->strict();
        $store->expects('consumeMany')
            ->with([$first, $second], 'uploads')
            ->returns([
                new LimitResult(true, 2, 2, 0, 0),
                new LimitResult(false, 1, 0, 60_000_000, 60_000_000),
            ]);

        $manager = $this->mockRateLimiter();
        $manager->allows('limiter')->returns(fn () => [$first, $second]);
        $manager->allows('limiterStore')->returns(null);
        $manager->allows('store')->returns($store);

        $job = Double::for(RateLimitedTestJob::class)->strict();
        $job->expects('release')->with(63)->returns(null);

        $nextCalls = 0;
        (new RateLimited('uploads'))->handle($job, function () use (&$nextCalls): void {
            ++$nextCalls;
        });

        $this->assertSame(0, $nextCalls);
    }

    public function testUnlimitedNamedQueueLimiterBypassesStorage(): void
    {
        $manager = $this->mockRateLimiter();
        $manager->expects('limiter')->with('uploads')->returns(fn () => Limit::none());
        $manager->expects('store')->never();

        $result = (new RateLimited('uploads'))->handle(
            new stdClass,
            fn (): string => 'handled',
        );

        $this->assertSame('handled', $result);
    }

    /**
     * Create a mock RateLimiter and set up the container.
     */
    protected function mockRateLimiter(): DoubleInterface
    {
        $limiter = Double::for(RateLimiter::class, override: true)->strict();

        $container = new Container;
        $container->instance(RateLimiter::class, $limiter->instance());
        Container::setInstance($container);

        return $limiter;
    }
}

class RateLimitedTestJob
{
    use InteractsWithQueue;
}
