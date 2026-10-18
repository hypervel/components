<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Feature;

use DateInterval;
use Firebase\JWT\JWT;
use Hypervel\Passport\Bridge\ParsedKey;
use Hypervel\Passport\Client;
use Hypervel\Passport\Database\Factories\ClientFactory;
use Hypervel\Passport\Database\Factories\SigningKeyFactory;
use Hypervel\Passport\Passport;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\Token;
use Hypervel\RateLimiter\Limit;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\RateLimiter;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Testing\TestResponse;
use Hypervel\Tests\Passport\Fixtures\UserFactory;
use PHPUnit\Framework\Attributes\TestWith;

#[WithConfig('passport.issuer', 'https://auth.example')]
class TokenManagementTest extends PassportTestCase
{
    use WithHypervelMigrations;

    /**
     * Configure a scope and the application's consent view.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Passport::tokensCan(['read' => 'Read']);
        Passport::authorizationView(fn (array $parameters): array => $parameters);
    }

    public function testAccessIntrospectionReturnsOnlyPermittedMetadataAndRevocationIsIdempotent(): void
    {
        Passport::accessTokenClaimsUsing(fn (): array => ['private_context' => 'do not publish']);
        $client = ClientFactory::new()->create();
        $credentials = $this->issue($client);
        $token = Passport::token()->newQuery()->sole();
        $response = $this->form('introspect', $client, ['token' => $credentials['access_token'], 'token_type_hint' => 'refresh_token'])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeaderMissing('Set-Cookie');
        $response->assertExactJson([
            'active' => true, 'client_id' => $client->id, 'scope' => 'read', 'token_type' => 'Bearer',
            'sub' => (string) $token->user_id, 'aud' => [$client->id], 'iss' => 'https://auth.example',
            'jti' => $token->id, 'exp' => $token->expires_at->getTimestamp(),
            'iat' => $response->json('iat'), 'nbf' => $response->json('nbf'),
        ]);
        $this->assertEqualsWithDelta(time(), $response->json('iat'), 3);
        $this->assertSame($response->json('iat'), $response->json('nbf'));

        $this->form('introspect', $client, ['token' => $credentials['authorization_code']])->assertExactJson(['active' => false]);
        $this->form('revoke', $client, ['token' => $credentials['authorization_code']])->assertOk()->assertContent('');
        $this->assertFalse($token->fresh()->revoked);

        $this->form('revoke', $client, ['token' => $credentials['access_token']])->assertOk()->assertContent('');
        $this->form('revoke', $client, ['token' => $credentials['access_token']])->assertOk()->assertContent('');
        $this->form('introspect', $client, ['token' => $credentials['access_token']])->assertExactJson(['active' => false]);
        $this->assertTrue($token->fresh()->revoked);
        $this->assertFalse($token->refreshToken->revoked);
    }

    public function testOtherClientsCannotRevokeOrInspectCredentialsWithoutAccessTokenPermission(): void
    {
        $owner = ClientFactory::new()->create();
        $other = ClientFactory::new()->create();
        $credentials = $this->issue($owner);

        foreach (['access_token', 'refresh_token'] as $type) {
            $this->form('introspect', $other, ['token' => $credentials[$type]])->assertExactJson(['active' => false]);
            $this->form('revoke', $other, ['token' => $credentials[$type]])->assertOk()->assertContent('');
            $this->form('introspect', $owner, ['token' => $credentials[$type]])->assertJsonPath('active', true);
        }

        Passport::authorizeIntrospectionUsing(fn (Client $client, Token $token): bool => $client->id === $other->id && $token->can('read'));
        $this->form('introspect', $other, ['token' => $credentials['access_token']])->assertJsonPath('active', true);
        $this->form('introspect', $other, ['token' => $credentials['refresh_token']])->assertExactJson(['active' => false]);
        $owner->revoke();
        $this->form('introspect', $other, ['token' => $credentials['access_token']])->assertExactJson(['active' => false]);
    }

    public function testPublicClientsMayRevokeButCannotIntrospect(): void
    {
        $client = ClientFactory::new()->asPublic()->create();
        $credentials = $this->issue($client);
        $this->form('introspect', $client, ['token' => $credentials['access_token']])
            ->assertUnauthorized()->assertJsonPath('error', 'invalid_client');
        $this->form('revoke', $client, ['token' => $credentials['refresh_token']])->assertOk();
        $this->assertTrue(Passport::token()->newQuery()->sole()->revoked);
        $this->assertNotNull(Passport::refreshTokenFamily()->newQuery()->sole()->revoked_at);
    }

    public function testPolicyDenialDoesNotPreventExplicitRevocation(): void
    {
        $client = ClientFactory::new()->create();
        $credentials = $this->issue($client);
        Passport::authenticateAccessTokensUsing(fn (): bool => false);
        $this->form('introspect', $client, ['token' => $credentials['access_token']])->assertExactJson(['active' => false]);
        $this->form('revoke', $client, ['token' => $credentials['access_token']])->assertOk();
        $this->assertTrue(Passport::token()->newQuery()->sole()->revoked);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testRotatedRefreshRevocationReachesItsSuccessorUnlessThePresentedCredentialExpired(bool $expired): void
    {
        $client = ClientFactory::new()->asPublic()->create();
        $credentials = $this->issue($client);
        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token', 'client_id' => $client->id,
            'refresh_token' => $credentials['refresh_token'],
        ])->assertOk();

        $value = $credentials['refresh_token'];
        if ($expired) {
            $claims = json_decode($this->app->make(ServerFactory::class)->decryptToken($value), true, flags: JSON_THROW_ON_ERROR);
            $this->travelTo(now()->setTimestamp($claims['expire_time'])->addSecond());
            // Extending the stored row cannot extend the encrypted credential's lifetime.
            Passport::refreshToken()->newQuery()->whereKey($claims['refresh_token_id'])->update(['expires_at' => now()->addDay()]);
        }

        DB::beginTransaction();
        try {
            $this->form('revoke', $client, ['token' => $value])->assertOk()->assertContent('');
            DB::commit();
        } finally {
            if (DB::connection()->hasApplicableTransaction()) {
                DB::rollBack();
            }
        }
        $this->assertSame($expired ? 1 : 0, Passport::refreshToken()->newQuery()->where('revoked', false)->count());
        $this->assertSame($expired ? 1 : 0, Passport::token()->newQuery()->where('revoked', false)->count());
        $this->assertSame($expired, Passport::refreshTokenFamily()->newQuery()->sole()->revoked_at === null);
    }

    public function testRefreshIntrospectionUsesItsOwnLifetimeRatherThanTheAccessTokenLifetime(): void
    {
        Passport::tokensExpireIn(new DateInterval('PT0S'));
        $client = ClientFactory::new()->create();
        $credentials = $this->issue($client);
        $this->form('introspect', $client, ['token' => $credentials['access_token']])->assertExactJson(['active' => false]);
        $refresh = Passport::refreshToken()->newQuery()->sole();
        $this->form('introspect', $client, ['token' => $credentials['refresh_token']])->assertExactJson([
            'active' => true, 'client_id' => $client->id, 'scope' => 'read',
            'sub' => (string) $refresh->accessToken->user_id, 'iss' => 'https://auth.example',
            'exp' => $refresh->expires_at->getTimestamp(), 'jti' => $refresh->id,
        ]);
        $refresh->revoke();
        $this->form('introspect', $client, ['token' => $credentials['refresh_token']])->assertExactJson(['active' => false]);
    }

    public function testIntrospectionDoesNotSpendThePublicEndpointsRateBudget(): void
    {
        $client = ClientFactory::new()->create();
        $credentials = $this->issue($client);
        RateLimiter::for('passport', static fn (): Limit => Limit::perMinute(1)->by('resource-server'));

        $this->form('introspect', $client, ['token' => $credentials['access_token']])->assertOk()->assertJsonPath('active', true);
        $this->form('introspect', $client, ['token' => $credentials['access_token']])->assertOk()->assertJsonPath('active', true);
        $this->form('revoke', $client, ['token' => $credentials['access_token']])->assertOk();
        $this->form('revoke', $client, ['token' => $credentials['access_token']])->assertTooManyRequests();
    }

    #[TestWith(['not-a-token'])]
    #[TestWith(['bad.jwt.token'])]
    public function testInvalidCredentialsAreInactiveAndRevocationDoesNotDiscloseThem(string $value): void
    {
        $client = ClientFactory::new()->create();
        $this->form('introspect', $client, ['token' => $value])->assertOk()->assertExactJson(['active' => false]);
        $this->form('revoke', $client, ['token' => $value])->assertOk()->assertContent('');
    }

    #[TestWith(['revoke'])]
    #[TestWith(['introspect'])]
    public function testRequestsRequireFormEncodingAuthenticationAndAToken(string $endpoint): void
    {
        $client = ClientFactory::new()->create();
        $this->postJson('/oauth/' . $endpoint, ['token' => 'unknown'])->assertBadRequest()->assertJsonPath('error', 'invalid_request');
        $this->form($endpoint, $client, ['client_secret' => 'wrong', 'token' => 'unknown'])
            ->assertUnauthorized()->assertJsonPath('error', 'invalid_client')->assertHeaderMissing('Location');
        $this->form($endpoint, $client)->assertBadRequest()->assertJsonPath('error', 'invalid_request');
    }

    #[WithConfig('passport.replay.store', 'database')]
    public function testKeyAuthenticatedConfidentialClientsCanInspectAnotherClientsAuthorizedAccessToken(): void
    {
        $owner = ClientFactory::new()->create();
        $credentials = $this->issue($owner);
        $material = (new SigningKeyFactory)->definition();
        $client = ClientFactory::new()->create([
            'secret' => null, 'token_endpoint_auth_method' => 'private_key_jwt',
            'token_endpoint_auth_signing_alg' => 'RS256',
            'jwks' => ['keys' => [ParsedKey::fromPublicKey($material['public_key'])->getJwk()]],
        ]);
        Passport::authorizeIntrospectionUsing(fn (Client $caller): bool => $caller->id === $client->id);
        $this->form('introspect', $client, [
            'token' => $credentials['access_token'],
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => JWT::encode([
                'iss' => $client->id, 'sub' => $client->id, 'aud' => 'https://auth.example',
                'jti' => Str::random(40), 'iat' => time(), 'exp' => time() + 120,
            ], $material['private_key'], 'RS256'),
        ])->assertOk()->assertJsonPath('active', true)->assertJsonPath('client_id', $owner->id);
    }

    /**
     * Submit an authenticated protocol form.
     *
     * @param array<string, string> $parameters
     */
    protected function form(string $endpoint, Client $client, array $parameters = []): TestResponse
    {
        return $this->post('/oauth/' . $endpoint, [
            'client_id' => $client->id,
            ...($client->plainSecret === null ? [] : ['client_secret' => $client->plainSecret]),
            ...$parameters,
        ], ['Content-Type' => 'application/x-www-form-urlencoded']);
    }

    /**
     * Authorize through the browser and exchange a PKCE code.
     *
     * @return array<string, mixed>
     */
    protected function issue(Client $client): array
    {
        $this->actingAs(UserFactory::new()->create(), 'web');
        $verifier = Str::random(64);
        $decision = $this->get('/oauth/authorize?' . http_build_query([
            'client_id' => $client->id, 'response_type' => 'code', 'scope' => 'read',
            'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        ]))->assertOk()->json('authToken');
        $location = $this->post('/oauth/authorize/consent', ['auth_token' => $decision])->assertRedirect()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $parameters);

        $credentials = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client->id,
            'client_secret' => $client->plainSecret, 'code' => $parameters['code'], 'code_verifier' => $verifier,
        ])->assertOk()->json();

        return [...$credentials, 'authorization_code' => $parameters['code']];
    }
}
