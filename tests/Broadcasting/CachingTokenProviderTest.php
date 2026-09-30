<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\Mercure\CachingTokenProvider;
use Hypervel\Tests\TestCase;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;

class CachingTokenProviderTest extends TestCase
{
    public function testItMintsOnceWhileTheTokenIsFresh(): void
    {
        $inner = new CountingTokenProvider($this->tokenWithClaims(['exp' => time() + 3600]));
        $provider = new CachingTokenProvider($inner);

        $first = $provider->getJwt();

        $this->assertSame($first, $provider->getJwt());
        $this->assertSame($first, $provider->getJwt());
        $this->assertSame(1, $inner->calls);
    }

    public function testItReMintsOnceTheTokenNearsItsExpiry(): void
    {
        $inner = new CountingTokenProvider($this->tokenWithClaims(['exp' => time() + 10]));

        $provider = new CachingTokenProvider($inner, 30);

        $provider->getJwt();
        $provider->getJwt();

        $this->assertSame(2, $inner->calls);
    }

    public function testATokenWithoutExpIsCachedForever(): void
    {
        $inner = new CountingTokenProvider($this->tokenWithClaims(['sub' => 'app']));

        $provider = new CachingTokenProvider($inner);

        $provider->getJwt();
        $provider->getJwt();

        $this->assertSame(1, $inner->calls);
    }

    public function testItReplacesTheSingleCachedEntryWhenItsContextChanges(): void
    {
        $audience = 'https://first.test/.well-known/mercure';
        $inner = new CountingTokenProvider($this->tokenWithClaims(['exp' => time() + 3600]));
        $provider = new CachingTokenProvider($inner, cacheKeyResolver: static function () use (&$audience): string {
            return $audience;
        });

        $provider->getJwt();
        $provider->getJwt();
        $this->assertSame(1, $inner->calls);

        $audience = 'https://second.test/.well-known/mercure';
        $provider->getJwt();
        $provider->getJwt();
        $this->assertSame(2, $inner->calls);

        $audience = 'https://first.test/.well-known/mercure';
        $provider->getJwt();
        $this->assertSame(3, $inner->calls);
    }

    /**
     * Build a token carrying the given claims.
     */
    protected function tokenWithClaims(array $claims): string
    {
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        return $encode(['alg' => 'none']) . '.' . $encode($claims) . '.';
    }
}

class CountingTokenProvider implements TokenProviderInterface
{
    public int $calls = 0;

    /**
     * Create a provider for the given token.
     */
    public function __construct(protected string $jwt)
    {
    }

    /**
     * Count and return the requested token.
     */
    public function getJwt(): string
    {
        ++$this->calls;

        return $this->jwt;
    }
}
