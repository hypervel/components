<?php

declare(strict_types=1);

namespace Hypervel\Tests\RateLimiter;

use Hypervel\RateLimiter\Backoff;
use Hypervel\RateLimiter\KeyResolver;
use Hypervel\RateLimiter\LeakyBucket;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\SlidingWindow;
use Hypervel\Tests\TestCase;
use Stringable;

enum KeyResolverKey: int
{
    case One = 1;
}

class KeyResolverTest extends TestCase
{
    public function testCanonicalIdentityHasStableGoldenVectors(): void
    {
        $resolver = new KeyResolver('app', static fn (string $name): ?string => 'tenant:7');

        $this->assertSame(
            'd91bec8237651325e9d9bc0c89d9119b',
            $resolver->resolve(Limit::perMinute(60)->by('user:1'), 'api'),
        );
        $this->assertSame(
            '28f58bbedbb7a4f75f8e7899ded97686',
            $resolver->resolve(SlidingWindow::perMinute(60)->by('user:1'), 'api'),
        );
        $this->assertSame(
            '72519c9daf2298e61f4ce018cacde4ac',
            $resolver->resolve(LeakyBucket::perSecond(100)->burst(200)->by('user:1'), 'api'),
        );
        $this->assertSame(
            'c80de4e32af3dae58519b7d54ceb3c12',
            $resolver->resolve(Backoff::exponential(
                after: 5,
                initialDelay: 1,
                maxDelay: 300,
                resetAfter: 3600,
            )->by('login')),
        );
    }

    // REMOVED: Laravel's fallback-key collision handling is replaced by
    // parameter-sensitive canonical policy identities.

    public function testIdentityIncludesEveryStableDomain(): void
    {
        $resolver = new KeyResolver('app', static fn (string $name): ?string => 'tenant:7');
        $policy = Limit::perMinute(60)->by('user:1');
        $key = $resolver->resolve($policy, 'api');

        $this->assertNotSame($key, (new KeyResolver('other', static fn (): ?string => 'tenant:7'))->resolve($policy, 'api'));
        $this->assertNotSame($key, $resolver->resolve($policy, 'web'));
        $this->assertNotSame($key, (new KeyResolver('app', static fn (): ?string => 'tenant:8'))->resolve($policy, 'api'));
        $this->assertNotSame($key, $resolver->resolve($policy->by('user:2'), 'api'));
        $this->assertNotSame($key, $resolver->resolve(Limit::perMinute(61)->by('user:1'), 'api'));
        $this->assertNotSame($key, $resolver->resolve(LeakyBucket::perMinute(60)->by('user:1'), 'api'));
        $this->assertNotSame($key, $resolver->resolve($policy->globally(), 'api'));
    }

    public function testRequestCostAndCallbacksDoNotChangeIdentity(): void
    {
        $resolver = new KeyResolver('app', static fn (): ?string => null);
        $policy = Limit::perMinute(60)->by('user:1');
        $key = $resolver->resolve($policy);

        $this->assertSame($key, $resolver->resolve($policy->cost(5)));
        $this->assertSame($key, $resolver->resolve($policy->after(static fn (): bool => true)));
        $this->assertSame($key, $resolver->resolve($policy->response(static fn (): string => 'limited')));
    }

    public function testSlidingWindowIdentityIncludesStablePolicySettings(): void
    {
        $resolver = new KeyResolver('app', static fn (): ?string => null);
        $policy = SlidingWindow::perMinute(60)->by('user:1');
        $key = $resolver->resolve($policy);

        $this->assertNotSame($key, $resolver->resolve(SlidingWindow::perMinute(61)->by('user:1')));
        $this->assertNotSame($key, $resolver->resolve(SlidingWindow::perMinutes(2, 60)->by('user:1')));
        $this->assertNotSame($key, $resolver->resolve(Limit::perMinute(60)->by('user:1')));
        $this->assertNotSame($key, $resolver->resolve($policy->globally()));
        $this->assertSame($key, $resolver->resolve($policy->cost(5)));
        $this->assertSame($key, $resolver->resolve($policy->after(static fn (): bool => true)));
        $this->assertSame($key, $resolver->resolve($policy->response(static fn (): string => 'limited')));
    }

    public function testMissingScopeResolverMatchesAResolverReturningNull(): void
    {
        $policy = Limit::perMinute(60)->by('user:1');

        $this->assertSame(
            (new KeyResolver('app', static fn (): ?string => null))->resolve($policy, 'api'),
            (new KeyResolver('app'))->resolve($policy, 'api'),
        );
    }

    public function testEquivalentCallerKeysNormalizeToTheSameIdentity(): void
    {
        $resolver = new KeyResolver('app', static fn (): ?string => null);
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return '1';
            }
        };

        $keys = [
            $resolver->resolve(Limit::perMinute(1)->by(1)),
            $resolver->resolve(Limit::perMinute(1)->by('1')),
            $resolver->resolve(Limit::perMinute(1)->by($stringable)),
            $resolver->resolve(Limit::perMinute(1)->by(KeyResolverKey::One)),
        ];

        $this->assertCount(1, array_unique($keys));
        $this->assertSame(
            $resolver->resolve(Limit::perMinute(1)->by(null)),
            $resolver->resolve(Limit::perMinute(1)->by('')),
        );
    }

    public function testArbitrarySegmentsCannotCreateAmbiguousIdentities(): void
    {
        $resolver = new KeyResolver('app', static fn (): ?string => null);

        $this->assertNotSame(
            $resolver->resolve(Limit::perMinute(1)->by('bc'), 'a'),
            $resolver->resolve(Limit::perMinute(1)->by('c'), 'ab'),
        );
        $this->assertNotSame(
            $resolver->resolve(Limit::perMinute(1)->by('1:key'), 'limiter'),
            $resolver->resolve(Limit::perMinute(1)->by('key'), 'limiter1:'),
        );
    }

    public function testDirectAndGlobalPoliciesDoNotInvokeTheNamedScopeResolver(): void
    {
        $calls = 0;
        $resolver = new KeyResolver('app', static function () use (&$calls): ?string {
            ++$calls;

            return 'scope';
        });

        $resolver->resolve(Limit::perMinute(1));
        $resolver->resolve(Limit::perMinute(1)->globally(), 'api');

        $this->assertSame(0, $calls);

        $resolver->resolve(Limit::perMinute(1), 'api');

        $this->assertSame(1, $calls);
    }
}
