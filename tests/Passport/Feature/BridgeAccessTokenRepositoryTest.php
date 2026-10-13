<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Passport\Bridge\AccessToken;
use Hypervel\Passport\Bridge\AccessTokenRepository;
use Hypervel\Passport\Bridge\Client;
use Hypervel\Passport\Bridge\Scope;
use Hypervel\Passport\Events\AccessTokenCreated;
use Hypervel\Passport\Events\AccessTokenRevoked;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;

class BridgeAccessTokenRepositoryTest extends PassportTestCase
{
    use WithHypervelMigrations;

    public function testAccessTokensCanBePersisted(): void
    {
        $expiration = CarbonImmutable::now();

        Event::fake();

        $accessToken = new AccessToken('2', [new Scope('scopes')], new Client('client-id', 'name', ['redirect']));
        $accessToken->setIdentifier('1');
        $accessToken->setExpiryDateTime($expiration);

        $repository = new AccessTokenRepository(app('events'));

        $repository->persistNewAccessToken($accessToken);

        $this->assertDatabaseHas('oauth_access_tokens', [
            'id' => '1',
            'user_id' => '2',
            'client_id' => 'client-id',
            'scopes' => '["scopes"]',
            'revoked' => false,
            'expires_at' => $expiration,
        ]);

        Event::assertDispatched(fn (AccessTokenCreated $event) => $event->tokenId === '1'
            && $event->userId === '2'
            && $event->clientId === 'client-id');
    }

    public function testAccessTokensCanBeRevoked(): void
    {
        Event::fake();

        $accessToken = new AccessToken('2', [], new Client('client-id', 'name', ['redirect']));
        $accessToken->setIdentifier('token-id');
        $accessToken->setExpiryDateTime(CarbonImmutable::now());

        $repository = new AccessTokenRepository(app('events'));
        $repository->persistNewAccessToken($accessToken);

        $repository->revokeAccessToken('token-id');

        $this->assertDatabaseHas('oauth_access_tokens', ['id' => 'token-id', 'revoked' => true]);
        Event::assertDispatched(fn (AccessTokenRevoked $event) => $event->tokenId === 'token-id');
    }

    public function testAccessTokenRevokeEventIsNotDispatchedWhenNothingHappened(): void
    {
        Event::fake();

        $repository = new AccessTokenRepository(app('events'));
        $repository->revokeAccessToken('token-id');

        Event::assertNotDispatched(AccessTokenRevoked::class);
    }

    public function testCanGetNewAccessToken(): void
    {
        $repository = new AccessTokenRepository(app('events'));
        $client = new Client('client-id', 'name', ['redirect']);
        $scopes = [new Scope('place-orders'), new Scope('check-status')];
        $userIdentifier = '123';

        $token = $repository->getNewToken($client, $scopes, $userIdentifier);

        $this->assertInstanceOf(AccessToken::class, $token);
        $this->assertEquals($client, $token->getClient());
        $this->assertEquals($scopes, $token->getScopes());
        $this->assertEquals($userIdentifier, $token->getUserIdentifier());
    }
}
