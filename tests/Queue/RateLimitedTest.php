<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hyperf\Context\ApplicationContext;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hypervel\Cache\RateLimiter;
use Hypervel\Queue\Middleware\RateLimited;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use TypeError;

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

/**
 * @internal
 * @coversNothing
 */
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

    public function testConstructorWithIntBackedEnumThrowsTypeError(): void
    {
        $this->mockRateLimiter();

        $this->expectException(TypeError::class);

        new RateLimited(RateLimitedTestIntEnum::Primary);
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

    /**
     * Create a mock RateLimiter and set up the container.
     */
    protected function mockRateLimiter(): RateLimiter&MockInterface
    {
        $limiter = Mockery::mock(RateLimiter::class);

        $container = new Container(
            new DefinitionSource([
                RateLimiter::class => fn () => $limiter,
            ])
        );

        ApplicationContext::setContainer($container);

        return $limiter;
    }
}
