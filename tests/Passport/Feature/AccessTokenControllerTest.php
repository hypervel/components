<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use Hypervel\Contracts\Hashing\Hasher;
use Hypervel\Passport\Client;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Passport;
use Hypervel\Passport\Token;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;

class AccessTokenControllerTest extends PassportTestCase
{
    use WithHypervelMigrations;

    public function testGettingAccessTokenWithClientCredentialsGrant(): void
    {
        $this->withoutExceptionHandling();

        $user = UserFactory::new()->create([
            'email' => 'foo@gmail.com',
            'password' => $this->app->make(Hasher::class)->make('foobar123'),
        ]);

        /** @var Client $client */
        $client = ClientFactory::new()->asClientCredentials()->for($user, 'owner')->create();

        $response = $this->post(
            '/oauth/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => $client->getKey(),
                'client_secret' => $client->plainSecret,
            ]
        );

        $response->assertOk();

        $response->assertHeader('pragma', 'no-cache');
        $response->assertHeader('cache-control', 'no-store, private');
        $response->assertHeader('content-type', 'application/json; charset=UTF-8');

        $decodedResponse = $response->decodeResponseJson()->json();

        $this->assertArrayHasKey('token_type', $decodedResponse);
        $this->assertArrayHasKey('expires_in', $decodedResponse);
        $this->assertArrayHasKey('access_token', $decodedResponse);
        $this->assertSame('Bearer', $decodedResponse['token_type']);
        $this->assertEqualsWithDelta(31536000, $decodedResponse['expires_in'], 2);
    }

    public function testGettingAccessTokenWithClientCredentialsGrantInvalidClientSecret(): void
    {
        $user = UserFactory::new()->create([
            'email' => 'foo@gmail.com',
            'password' => $this->app->make(Hasher::class)->make('foobar123'),
        ]);

        /** @var Client $client */
        $client = ClientFactory::new()->asClientCredentials()->for($user, 'owner')->create();

        $response = $this->post(
            '/oauth/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => $client->getKey(),
                'client_secret' => $client->plainSecret . 'foo',
            ]
        );

        $response->assertStatus(401);

        $response->assertHeader('cache-control', 'no-cache, private');
        $response->assertHeader('content-type', 'application/json');

        $decodedResponse = $response->decodeResponseJson()->json();

        $this->assertArrayNotHasKey('token_type', $decodedResponse);
        $this->assertArrayNotHasKey('expires_in', $decodedResponse);
        $this->assertArrayNotHasKey('access_token', $decodedResponse);

        $this->assertArrayHasKey('error', $decodedResponse);
        $this->assertSame('invalid_client', $decodedResponse['error']);
        $this->assertArrayHasKey('error_description', $decodedResponse);
        $this->assertSame('Client authentication failed', $decodedResponse['error_description']);
        $this->assertArrayNotHasKey('hint', $decodedResponse);

        $this->assertSame(0, Token::count());
    }

    public function testGettingAccessTokenWithMalformedUuidClientId(): void
    {
        $this->withoutExceptionHandling();

        $response = $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => 'x',
            'client_secret' => 'secret',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error', 'invalid_client');
        $response->assertJsonPath('error_description', 'Client authentication failed');

        $this->assertSame(0, Token::count());
    }

    // REMOVED: Password-grant issuance and invalid-password/client-secret cases; use authorization code with PKCE.

    public function testGettingCustomResponseType(): void
    {
        $this->withoutExceptionHandling();
        Passport::$authorizationServerResponseType = new IdTokenResponse('foo_bar_open_id_token');

        $user = UserFactory::new()->create([
            'email' => 'foo@gmail.com',
            'password' => $this->app->make(Hasher::class)->make('foobar123'),
        ]);

        /** @var Client $client */
        $client = ClientFactory::new()->asClientCredentials()->for($user, 'owner')->create();

        $response = $this->post(
            '/oauth/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => $client->getKey(),
                'client_secret' => $client->plainSecret,
            ]
        );

        $response->assertOk();

        $decodedResponse = $response->decodeResponseJson()->json();

        $this->assertArrayHasKey('id_token', $decodedResponse);
        $this->assertSame('foo_bar_open_id_token', $decodedResponse['id_token']);
    }
}

class IdTokenResponse extends BearerTokenResponse
{
    /**
     * Create a response with the given ID token.
     */
    public function __construct(
        protected string $idToken,
    ) {
    }

    /**
     * Add the ID token to the access token response.
     */
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        return [
            'id_token' => $this->idToken,
        ];
    }
}
