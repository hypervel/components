<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit\Oauth2;

use DateTimeImmutable;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Http\Auth\AccessTokenAuthenticator;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Date;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

// Upstream's global clock is the framework Date clock.
class AccessTokenAuthenticatorTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testCanReturnIfItHasExpiredOrNot(): void
    {
        $accessToken = 'access';
        $refreshToken = 'refresh';
        $expiresAt = Date::now()->subMinutes(5)->toDateTimeImmutable();

        $authenticator = new AccessTokenAuthenticator($accessToken, $refreshToken, $expiresAt);

        $this->assertTrue($authenticator->isRefreshable());
        $this->assertFalse($authenticator->isNotRefreshable());
        $this->assertTrue($authenticator->hasExpired());
        $this->assertFalse($authenticator->hasNotExpired());
    }

    public function testCanBeConstructedWithoutARefreshTokenOrExpiry(): void
    {
        $authenticator = new AccessTokenAuthenticator('access');

        $this->assertSame('access', $authenticator->getAccessToken());
        $this->assertNull($authenticator->getRefreshToken());
        $this->assertNull($authenticator->getExpiresAt());
        $this->assertFalse($authenticator->isRefreshable());
        $this->assertTrue($authenticator->isNotRefreshable());
    }

    public function testCanBeConstructedWithJustAnAccessTokenAndExpiry(): void
    {
        $expiresAt = Date::now()->subMinutes(5)->toDateTimeImmutable();

        $authenticator = new AccessTokenAuthenticator('access', null, $expiresAt);

        $this->assertTrue($authenticator->hasExpired());
        $this->assertFalse($authenticator->hasNotExpired());
    }

    public function testItAllowsExpiresInToBeOptional(): void
    {
        $authenticator = new AccessTokenAuthenticator('access', 'refresh', null);

        $this->assertNull($authenticator->getExpiresAt());
        $this->assertTrue($authenticator->isRefreshable());
        $this->assertFalse($authenticator->isNotRefreshable());
    }

    public function testItCanUseTheGlobalClockForExpiryChecks(): void
    {
        $expiresAt = new DateTimeImmutable('2026-01-01T01:00:00+00:00');

        Date::setTestNow('2026-01-01T02:00:00+00:00');

        $authenticator = new AccessTokenAuthenticator('access', 'refresh', $expiresAt);

        $this->assertTrue($authenticator->hasExpired());
        $this->assertFalse($authenticator->hasNotExpired());
    }

    public function testTheAuthorizationHeaderUsesTheAccessTokenGetter(): void
    {
        $authenticator = new class('stored') extends AccessTokenAuthenticator {
            /**
             * Get the access token.
             */
            public function getAccessToken(): string
            {
                return 'resolved';
            }
        };

        $pendingRequest = (new TestConnector)->createPendingRequest(UserRequest::make()->authenticate($authenticator));

        $this->assertSame('Bearer resolved', $pendingRequest->headers()['Authorization']);
    }
}
