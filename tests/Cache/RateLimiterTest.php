<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\RateLimiter;
use Hypervel\Cache\RateLimiting\GlobalLimit;
use Hypervel\Cache\RateLimiting\Limit;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;

enum BackedEnumNamedRateLimiter: string
{
    case Api = 'api';
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
            'uses BackedEnum' => [BackedEnumNamedRateLimiter::Api, 'api'],
            'uses UnitEnum' => [UnitEnumNamedRateLimiter::ThirdParty, 'ThirdParty'],
            'uses normal string' => ['yolo', 'yolo'],
        ];
    }

    public function testForWithBackedEnumStoresUnderValue(): void
    {
        $rateLimiter = new RateLimiter(m::mock(Cache::class));
        $rateLimiter->for(BackedEnumNamedRateLimiter::Api, fn () => 'api-limit');

        // Can retrieve with enum
        $this->assertNotNull($rateLimiter->limiter(BackedEnumNamedRateLimiter::Api));

        // Can also retrieve with string value
        $this->assertNotNull($rateLimiter->limiter('api'));

        // Closure returns expected value
        $this->assertSame('api-limit', $rateLimiter->limiter(BackedEnumNamedRateLimiter::Api)());
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
        $limiter = $rateLimiter->limiter(BackedEnumNamedRateLimiter::Api);

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

        $rateLimiter->for(BackedEnumNamedRateLimiter::Api, fn () => 'api-limit');
        $rateLimiter->for(BackedEnumNamedRateLimiter::Web, fn () => 'web-limit');
        $rateLimiter->for(UnitEnumNamedRateLimiter::ThirdParty, fn () => 'third-party-limit');
        $rateLimiter->for('custom', fn () => 'custom-limit');

        $this->assertSame('api-limit', $rateLimiter->limiter(BackedEnumNamedRateLimiter::Api)());
        $this->assertSame('web-limit', $rateLimiter->limiter(BackedEnumNamedRateLimiter::Web)());
        $this->assertSame('third-party-limit', $rateLimiter->limiter(UnitEnumNamedRateLimiter::ThirdParty)());
        $this->assertSame('custom-limit', $rateLimiter->limiter('custom')());
    }

    public function testShouldUseOriginKeyAsPrefixWhenMultipleLimiterWithSameKey()
    {
        $rateLimiter = new RateLimiter(new Repository(new ArrayStore));

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

    public function testNamedLimiterKeyUsesCanonicalHashedAndRawFormats(): void
    {
        $rateLimiter = new RateLimiter(m::mock(Cache::class));
        $limit = Limit::perMinute(10)->by('user-1');
        $key = '3:api6:user-1';

        $this->assertSame(
            hash('xxh128', $key),
            $rateLimiter->resolveNamedLimiterKey('api', $limit),
        );
        $this->assertSame(
            $key,
            $rateLimiter->resolveNamedLimiterKey('api', $limit, shouldHashKeys: false),
        );
    }

    public function testNamedLimiterKeyIncludesResolvedScopeBeforeSingleHash(): void
    {
        $rateLimiter = new RateLimiter(m::mock(Cache::class));
        $resolvedName = null;
        $rateLimiter->resolveKeyScopeUsing(function (string $limiterName) use (&$resolvedName): string {
            $resolvedName = $limiterName;

            return 'account-1';
        });
        $limit = Limit::perMinute(10)->by('user-1');
        $key = '9:account-13:api6:user-1';

        $this->assertSame(
            hash('xxh128', $key),
            $rateLimiter->resolveNamedLimiterKey('api', $limit),
        );
        $this->assertSame(
            $key,
            $rateLimiter->resolveNamedLimiterKey('api', $limit, shouldHashKeys: false),
        );
        $this->assertSame('api', $resolvedName);
    }

    public function testNullScopeAndClearedResolverUseTheUnscopedKey(): void
    {
        $rateLimiter = new RateLimiter(m::mock(Cache::class));
        $limit = Limit::perMinute(10)->by('user-1');

        $rateLimiter->resolveKeyScopeUsing(fn () => null);
        $this->assertSame(
            hash('xxh128', '3:api6:user-1'),
            $rateLimiter->resolveNamedLimiterKey('api', $limit),
        );

        $rateLimiter->resolveKeyScopeUsing(fn () => 'account-1');
        $rateLimiter->resolveKeyScopeUsing(null);

        $this->assertSame(
            hash('xxh128', '3:api6:user-1'),
            $rateLimiter->resolveNamedLimiterKey('api', $limit),
        );
    }

    public function testGlobalLimitNeverInvokesScopeResolver(): void
    {
        $rateLimiter = new RateLimiter(m::mock(Cache::class));
        $rateLimiter->resolveKeyScopeUsing(function (): never {
            throw new RuntimeException('Scope resolver should not run.');
        });
        $limit = new GlobalLimit(10);

        $this->assertSame(
            hash('xxh128', '3:api0:'),
            $rateLimiter->resolveNamedLimiterKey('api', $limit),
        );
        $this->assertSame(
            '3:api0:',
            $rateLimiter->resolveNamedLimiterKey('api', $limit, shouldHashKeys: false),
        );
    }

    public function testNamedLimiterKeyAcceptsEmptyAndFallbackLimitKeys(): void
    {
        $rateLimiter = new RateLimiter(m::mock(Cache::class));
        $emptyLimit = Limit::perMinute(10);
        $fallbackLimit = Limit::perMinute(10)->by($emptyLimit->fallbackKey());

        $this->assertSame(
            hash('xxh128', '3:api0:'),
            $rateLimiter->resolveNamedLimiterKey('api', $emptyLimit),
        );
        $this->assertSame(
            hash('xxh128', '3:api20:attempts:10:decay:60'),
            $rateLimiter->resolveNamedLimiterKey('api', $fallbackLimit),
        );
    }

    public function testNamedLimiterKeysPreserveNameAndKeyBoundaries(): void
    {
        $rateLimiter = new RateLimiter(m::mock(Cache::class));
        $first = Limit::perMinute(10)->by('c');
        $second = Limit::perMinute(10)->by('bc');

        $this->assertNotSame(
            $rateLimiter->resolveNamedLimiterKey('ab', $first),
            $rateLimiter->resolveNamedLimiterKey('a', $second),
        );
        $this->assertNotSame(
            $rateLimiter->resolveNamedLimiterKey('ab', $first, shouldHashKeys: false),
            $rateLimiter->resolveNamedLimiterKey('a', $second, shouldHashKeys: false),
        );
    }

    public function testNamedLimiterKeysPreserveScopeAndNameBoundaries(): void
    {
        $first = new RateLimiter(m::mock(Cache::class));
        $first->resolveKeyScopeUsing(fn () => 'scope:one');
        $second = new RateLimiter(m::mock(Cache::class));
        $second->resolveKeyScopeUsing(fn () => 'scope');
        $limit = Limit::perMinute(10)->by('user-1');

        $this->assertNotSame(
            $first->resolveNamedLimiterKey('api', $limit),
            $second->resolveNamedLimiterKey('one:api', $limit),
        );
        $this->assertNotSame(
            $first->resolveNamedLimiterKey('api', $limit, shouldHashKeys: false),
            $second->resolveNamedLimiterKey('one:api', $limit, shouldHashKeys: false),
        );
    }

    public function testNamedLimiterKeysPreserveOptionalScopeArity(): void
    {
        $unscoped = new RateLimiter(m::mock(Cache::class));
        $scoped = new RateLimiter(m::mock(Cache::class));
        $scoped->resolveKeyScopeUsing(fn () => 'account-1');
        $limit = Limit::perMinute(10)->by('user-1');

        $this->assertNotSame(
            $unscoped->resolveNamedLimiterKey('account-1:api', $limit),
            $scoped->resolveNamedLimiterKey('api', $limit),
        );
        $this->assertNotSame(
            $unscoped->resolveNamedLimiterKey('account-1:api', $limit, shouldHashKeys: false),
            $scoped->resolveNamedLimiterKey('api', $limit, shouldHashKeys: false),
        );
    }
}
