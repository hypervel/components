<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\RateLimiter;
use Hypervel\Contracts\Cache\Factory as Cache;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use TypeError;

enum BackedEnumNamedRateLimiter: string
{
    case API = 'api';
    case Web = 'web';
}

enum IntBackedEnumNamedRateLimiter: int
{
    case First = 1;
    case Second = 2;
}

enum UnitEnumNamedRateLimiter
{
    case ThirdParty;
    case Internal;
}

/**
 * @internal
 * @coversNothing
 */
class RateLimiterEnumTest extends TestCase
{
    #[DataProvider('registerNamedRateLimiterDataProvider')]
    public function testRegisterNamedRateLimiter(mixed $name, string $expected): void
    {
        $reflectedLimitersProperty = new ReflectionProperty(RateLimiter::class, 'limiters');

        $rateLimiter = new RateLimiter(m::mock(Cache::class));
        $rateLimiter->for($name, fn () => 'limit');

        $limiters = $reflectedLimitersProperty->getValue($rateLimiter);

        $this->assertArrayHasKey($expected, $limiters);

        $limiterClosure = $rateLimiter->limiter($name);

        $this->assertNotNull($limiterClosure);
    }

    public static function registerNamedRateLimiterDataProvider(): array
    {
        return [
            'uses BackedEnum' => [BackedEnumNamedRateLimiter::API, 'api'],
            'uses UnitEnum' => [UnitEnumNamedRateLimiter::ThirdParty, 'ThirdParty'],
            'uses normal string' => ['yolo', 'yolo'],
        ];
    }

    public function testForWithBackedEnumStoresUnderValue(): void
    {
        $rateLimiter = new RateLimiter(m::mock(Cache::class));
        $rateLimiter->for(BackedEnumNamedRateLimiter::API, fn () => 'api-limit');

        // Can retrieve with enum
        $this->assertNotNull($rateLimiter->limiter(BackedEnumNamedRateLimiter::API));

        // Can also retrieve with string value
        $this->assertNotNull($rateLimiter->limiter('api'));

        // Closure returns expected value
        $this->assertSame('api-limit', $rateLimiter->limiter(BackedEnumNamedRateLimiter::API)());
    }

    public function testForWithUnitEnumStoresUnderName(): void
    {
        $rateLimiter = new RateLimiter(m::mock(Cache::class));
        $rateLimiter->for(UnitEnumNamedRateLimiter::ThirdParty, fn () => 'third-party-limit');

        // Can retrieve with enum
        $this->assertNotNull($rateLimiter->limiter(UnitEnumNamedRateLimiter::ThirdParty));

        // Can also retrieve with string name (PascalCase)
        $this->assertNotNull($rateLimiter->limiter('ThirdParty'));

        // Closure returns expected value
        $this->assertSame('third-party-limit', $rateLimiter->limiter(UnitEnumNamedRateLimiter::ThirdParty)());
    }

    public function testLimiterReturnsNullForNonExistentEnum(): void
    {
        $rateLimiter = new RateLimiter(m::mock(Cache::class));

        $this->assertNull($rateLimiter->limiter(BackedEnumNamedRateLimiter::Web));
        $this->assertNull($rateLimiter->limiter(UnitEnumNamedRateLimiter::Internal));
    }

    public function testBackedEnumAndStringInteroperability(): void
    {
        $rateLimiter = new RateLimiter(m::mock(Cache::class));

        // Register with string
        $rateLimiter->for('api', fn () => 'string-registered');

        // Retrieve with BackedEnum that has same value
        $limiter = $rateLimiter->limiter(BackedEnumNamedRateLimiter::API);

        $this->assertNotNull($limiter);
        $this->assertSame('string-registered', $limiter());
    }

    public function testUnitEnumAndStringInteroperability(): void
    {
        $rateLimiter = new RateLimiter(m::mock(Cache::class));

        // Register with string (matching UnitEnum name)
        $rateLimiter->for('ThirdParty', fn () => 'string-registered');

        // Retrieve with UnitEnum
        $limiter = $rateLimiter->limiter(UnitEnumNamedRateLimiter::ThirdParty);

        $this->assertNotNull($limiter);
        $this->assertSame('string-registered', $limiter());
    }

    public function testMultipleEnumLimitersCanCoexist(): void
    {
        $rateLimiter = new RateLimiter(m::mock(Cache::class));

        $rateLimiter->for(BackedEnumNamedRateLimiter::API, fn () => 'api-limit');
        $rateLimiter->for(BackedEnumNamedRateLimiter::Web, fn () => 'web-limit');
        $rateLimiter->for(UnitEnumNamedRateLimiter::ThirdParty, fn () => 'third-party-limit');
        $rateLimiter->for('custom', fn () => 'custom-limit');

        $this->assertSame('api-limit', $rateLimiter->limiter(BackedEnumNamedRateLimiter::API)());
        $this->assertSame('web-limit', $rateLimiter->limiter(BackedEnumNamedRateLimiter::Web)());
        $this->assertSame('third-party-limit', $rateLimiter->limiter(UnitEnumNamedRateLimiter::ThirdParty)());
        $this->assertSame('custom-limit', $rateLimiter->limiter('custom')());
    }

    public function testForWithIntBackedEnumThrowsTypeError(): void
    {
        $rateLimiter = new RateLimiter(m::mock(Cache::class));

        // Int-backed enum causes TypeError because resolveLimiterName() returns string
        $this->expectException(TypeError::class);
        $rateLimiter->for(IntBackedEnumNamedRateLimiter::First, fn () => 'limit');
    }
}
