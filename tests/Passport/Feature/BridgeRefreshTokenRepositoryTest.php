<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Passport\Bridge\AccessToken;
use Hypervel\Passport\Bridge\Client;
use Hypervel\Passport\Bridge\RefreshToken;
use Hypervel\Passport\Bridge\RefreshTokenRepository;
use Hypervel\Passport\Events\RefreshTokenCreated;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;

class BridgeRefreshTokenRepositoryTest extends PassportTestCase
{
    use WithHypervelMigrations;

    public function testAccessTokensCanBePersisted(): void
    {
        $expiration = CarbonImmutable::now();

        Event::fake();

        $accessToken = new AccessToken('3', [], new Client('client-id', 'name', ['redirect']));
        $accessToken->setIdentifier('2');

        $refreshToken = new RefreshToken;
        $refreshToken->setIdentifier('1');
        $refreshToken->setExpiryDateTime($expiration);
        $refreshToken->setAccessToken($accessToken);

        $repository = new RefreshTokenRepository(app('events'));

        $repository->persistNewRefreshToken($refreshToken);

        $this->assertDatabaseHas('oauth_refresh_tokens', [
            'id' => '1',
            'access_token_id' => '2',
            'revoked' => false,
            'expires_at' => $expiration,
        ]);

        Event::assertDispatched(fn (RefreshTokenCreated $event) => $event->refreshTokenId === '1'
            && $event->accessTokenId === '2');
    }

    public function testCanGetNewRefreshToken(): void
    {
        $repository = new RefreshTokenRepository(app('events'));

        $token = $repository->getNewRefreshToken();

        $this->assertInstanceOf(RefreshToken::class, $token);
    }
}
