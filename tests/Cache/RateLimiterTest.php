<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\RateLimiter;
use Hypervel\Cache\RateLimiting\Limit;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

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
class RateLimiterTest extends TestCase
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

    public function testShouldUseOriginKeyAsPrefixWhenMultipleLimiterWithSameKey()
    {
        $rateLimiter = new RateLimiter(new Repository(new ArrayStore()));

        $rateLimiter->for('user_limiter', fn (string $userId) => [
            Limit::perSecond(3)->by($userId),
            Limit::perMinute(5)->by($userId),
        ]);

        $userId1 = '123';
        $userId2 = '456';

        $limiterForUser1 = $rateLimiter->limiter('user_limiter')($userId1);
        $limiterForUser2 = $rateLimiter->limiter('user_limiter')($userId2);

        for ($i = 0; $i < 3; ++$i) {
            $this->assertFalse($rateLimiter->tooManyAttempts($limiterForUser1[0]->key, $limiterForUser1[0]->maxAttempts));
            $this->assertFalse($rateLimiter->tooManyAttempts($limiterForUser2[0]->key, $limiterForUser2[0]->maxAttempts));

            $rateLimiter->hit($limiterForUser1[0]->key, $limiterForUser1[0]->decaySeconds);
            $rateLimiter->hit($limiterForUser2[0]->key, $limiterForUser2[0]->decaySeconds);
        }

        $this->assertNotSame($limiterForUser1[0]->key, $limiterForUser2[0]->key);
        $this->assertNotSame($limiterForUser1[1]->key, $limiterForUser2[1]->key);
    }

    public function testForWithIntBackedEnumStoresUnderStringCastValue(): void
    {
        $rateLimiter = new RateLimiter(m::mock(Cache::class));
        $rateLimiter->for(IntBackedEnumNamedRateLimiter::First, fn () => 'int-limit');

        // Can retrieve with enum
        $this->assertNotNull($rateLimiter->limiter(IntBackedEnumNamedRateLimiter::First));

        // Can also retrieve with string-cast value
        $this->assertNotNull($rateLimiter->limiter('1'));

        // Closure returns expected value
        $this->assertSame('int-limit', $rateLimiter->limiter(IntBackedEnumNamedRateLimiter::First)());
    }
}
