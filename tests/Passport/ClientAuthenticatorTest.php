<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use Firebase\JWT\JWT;
use GuzzleHttp\Psr7\ServerRequest;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Cache\Factory;
use Hypervel\Http\Client\Destinations\DestinationPolicyException;
use Hypervel\Passport\Bridge\ParsedKey;
use Hypervel\Passport\Client;
use Hypervel\Passport\ClientAuthenticator;
use Hypervel\Passport\ClientJwt;
use Hypervel\Passport\ClientKeyRepository;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Database\Factories\SigningKeyFactory;
use Hypervel\Passport\JwkValidator;
use Hypervel\Passport\RemoteDocumentFetcher;
use Hypervel\Passport\ReplayCache;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\ServerSettings;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use League\OAuth2\Server\Exception\OAuthServerException;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;

class ClientAuthenticatorTest extends TestCase
{
    #[TestWith([null, 'client_secret_basic'])]
    #[TestWith([null, 'client_secret_post'])]
    #[TestWith(['client_secret_basic', 'client_secret_basic'])]
    #[TestWith(['client_secret_post', 'client_secret_post'])]
    #[TestWith(['none', 'none'])]
    public function testAuthenticatesRegisteredAndLegacyClientsWithoutChangingSecrets(?string $registered, string $method): void
    {
        $client = new Client([
            'id' => 'client', 'secret' => $method === 'none' ? null : ' secret:+ ',
            'token_endpoint_auth_method' => $registered,
        ]);
        $request = new ServerRequest('POST', 'https://auth.example/oauth/token');
        $request = $method === 'client_secret_basic'
            ? $request->withHeader('Authorization', 'Basic ' . base64_encode('client:' . urlencode($client->plainSecret)))
            : $request->withParsedBody(['client_id' => 'client', ...$method === 'none' ? [] : ['client_secret' => $client->plainSecret]]);

        $this->assertSame($client, $this->authenticator($client)->authenticate($request));
    }

    #[TestWith(['client_secret_basic', ['client_id' => 'client', 'client_secret' => 'secret'], null])]
    #[TestWith(['none', ['client_id' => 'client', 'client_secret' => 'secret'], null])]
    #[TestWith(['client_secret_post', ['client_id' => 'client', 'client_secret' => 'wrong'], null])]
    #[TestWith(['client_secret_post', ['client_id' => 'client'], 'Basic Y2xpZW50OnNlY3JldA=='])]
    #[TestWith(['client_secret_basic', ['client_id' => 'other'], 'Basic Y2xpZW50OnNlY3JldA=='])]
    #[TestWith(['client_secret_post', ['client_id' => 'client'], 'Bearer token'])]
    #[TestWith([null, ['client_id' => 'client'], null])]
    #[TestWith([null, ['client_id' => 'client', 'client_secret' => ''], null])]
    public function testRejectsIncorrectCredentialsOrARegisteredMethodMismatch(?string $method, array $parameters, ?string $header): void
    {
        $client = new Client(['id' => 'client', 'secret' => 'secret', 'token_endpoint_auth_method' => $method]);
        $request = (new ServerRequest('POST', '/token'))->withParsedBody($parameters);

        if ($header !== null) {
            $request = $request->withHeader('Authorization', $header);
        }

        $this->expectException(OAuthServerException::class);
        $this->expectExceptionMessage('Client authentication failed');
        $this->authenticator($client)->authenticate($request);
    }

    public function testMultipleAuthenticationMethodsAreRejectedBeforeClientLookup(): void
    {
        $request = (new ServerRequest('POST', '/token'))
            ->withHeader('Authorization', 'Basic ' . base64_encode('client:secret'))
            ->withParsedBody(['client_id' => 'client', 'client_secret' => 'secret']);
        $clients = m::mock(ClientRepository::class);
        $clients->shouldNotReceive('findActive');
        $authenticator = new ClientAuthenticator(
            $clients,
            m::mock(ClientKeyRepository::class),
            m::mock(ReplayCache::class),
            m::mock(ServerFactory::class),
        );

        $this->expectException(OAuthServerException::class);
        $this->expectExceptionMessage('invalid');
        $authenticator->authenticate($request);
    }

    #[TestWith([null, false])]
    #[TestWith(['none', false])]
    #[TestWith([null, true])]
    public function testEmptyPasswordBasicIdentifiesPublicClientsButCannotAuthenticateConfidentialClients(?string $method, bool $confidential): void
    {
        $client = new Client([
            'id' => 'client', 'secret' => $confidential ? 'secret' : null, 'token_endpoint_auth_method' => $method,
        ]);
        $request = (new ServerRequest('POST', '/token'))->withHeader('Authorization', 'Basic ' . base64_encode('client:'));

        try {
            $this->assertSame($client, $this->authenticator($client)->authenticate($request));
            $this->assertFalse($confidential);
        } catch (OAuthServerException $exception) {
            $this->assertTrue($confidential);
            $this->assertSame('invalid_client', $exception->getErrorType());
            $this->assertSame(401, $exception->getHttpStatusCode());
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testPublicClientsWithoutARegisteredMethodIgnoreSuppliedSecrets(bool $basic): void
    {
        $client = new Client(['id' => 'client', 'secret' => null]);
        $request = new ServerRequest('POST', '/token');
        $request = $basic
            ? $request->withHeader('Authorization', 'Basic ' . base64_encode('client:unused-secret'))
            : $request->withParsedBody(['client_id' => 'client', 'client_secret' => 'unused-secret']);

        $this->assertSame($client, $this->authenticator($client)->authenticate($request));
    }

    #[TestWith(['https://auth.example'])]
    #[TestWith([['https://auth.example']])]
    public function testSignedAssertionsUseTheIssuerAndCannotBeReplayed(string|array $audience): void
    {
        [$client, $privateKey] = $this->assertionClient();
        $replays = m::mock(ReplayCache::class);
        $replays->shouldReceive('consume')->twice()->with(m::on(static fn (string $identifier): bool => json_decode($identifier, true) === [
            'client', 'https://auth.example', 'client', 'assertion',
        ]), m::type('int'))->andReturn(true, false);
        $authenticator = $this->authenticator($client, $replays);
        $request = $this->assertionRequest($privateKey, ['aud' => $audience]);

        $this->assertTrue($client->confidential());
        $this->assertSame($client, $authenticator->authenticate($request));
        $this->expectException(OAuthServerException::class);
        $authenticator->authenticate($request);
    }

    #[TestWith(['aud', 'https://auth.example/oauth/token'])]
    #[TestWith(['aud', 'https://auth.example/oauth/par'])]
    #[TestWith(['aud', ['https://auth.example', 'https://other.example']])]
    #[TestWith(['aud', []])]
    #[TestWith(['iss', 'other'])]
    #[TestWith(['iat', '123'])]
    #[TestWith(['iat', 9999999999])]
    #[TestWith(['exp', 1])]
    #[TestWith(['exp', 9999999999])]
    #[TestWith(['nbf', 9999999999])]
    #[TestWith(['jti', ''])]
    public function testInvalidAssertionsDoNotConsumeReplayEntries(string $claim, mixed $value): void
    {
        [$client, $privateKey] = $this->assertionClient();
        $replays = m::mock(ReplayCache::class);
        $replays->shouldNotReceive('consume');

        $this->expectException(OAuthServerException::class);
        $this->authenticator($client, $replays)->authenticate($this->assertionRequest($privateKey, [$claim => $value]));
    }

    public function testAssertionsRequireAConfiguredIssuer(): void
    {
        [$client, $privateKey] = $this->assertionClient();

        $this->expectException(OAuthServerException::class);
        $this->authenticator($client, issuer: null)->authenticate($this->assertionRequest($privateKey, ['aud' => null]));
    }

    #[TestWith([300, true])]
    #[TestWith([360, false])]
    public function testAssertionsWithoutIssuedAtHaveABoundedRemainingLifetime(int $remaining, bool $accepted): void
    {
        $now = $this->freezeSecond()->getTimestamp();
        [$client, $privateKey] = $this->assertionClient();
        $replays = m::mock(ReplayCache::class);
        $expiresAt = $now + $remaining + ClientJwt::$leeway;
        $assertion = JWT::encode([
            'iss' => 'client', 'sub' => 'client', 'aud' => 'https://auth.example',
            'jti' => 'assertion', 'exp' => $expiresAt,
        ], $privateKey, 'RS256');
        $request = (new ServerRequest('POST', '/token'))->withParsedBody([
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $assertion,
        ]);

        if ($accepted) {
            $replays->shouldReceive('consume')->once()->with(m::type('string'), $expiresAt + ClientJwt::$leeway)->andReturnTrue();
        } else {
            $replays->shouldNotReceive('consume');
            $this->expectException(OAuthServerException::class);
        }

        $this->assertSame($client, $this->authenticator($client, $replays)->authenticate($request));
    }

    #[TestWith(['iat', 60, true])]
    #[TestWith(['iat', 61, false])]
    #[TestWith(['nbf', 60, true])]
    #[TestWith(['nbf', 61, false])]
    public function testClientClockAllowanceDoesNotChangeOtherJwtConsumers(string $claim, int $offset, bool $accepted): void
    {
        $now = $this->freezeSecond()->getTimestamp();
        [$client, $privateKey] = $this->assertionClient();
        $replays = m::mock(ReplayCache::class);
        $request = $this->assertionRequest($privateKey, ['iat' => $now, 'exp' => $now + 120, $claim => $now + $offset]);
        $previousLeeway = JWT::$leeway;

        if ($accepted) {
            $replays->shouldReceive('consume')->once()->with(m::type('string'), $now + 180)->andReturnTrue();
        } else {
            $replays->shouldNotReceive('consume');
            $this->expectException(OAuthServerException::class);
        }

        try {
            $this->assertSame($client, $this->authenticator($client, $replays)->authenticate($request));
        } finally {
            $this->assertSame($previousLeeway, JWT::$leeway);
        }
    }

    public function testAnInvalidSignatureDoesNotConsumeReplayState(): void
    {
        [$client, $privateKey] = $this->assertionClient();
        $request = $this->assertionRequest($privateKey);
        $parameters = $request->getParsedBody();
        $parts = explode('.', $parameters['client_assertion']);
        $parts[2] = JWT::urlsafeB64Encode(str_repeat("\0", 256));
        $parameters['client_assertion'] = implode('.', $parts);

        $this->expectException(OAuthServerException::class);
        $this->authenticator($client)->authenticate($request->withParsedBody($parameters));
    }

    public function testUnsupportedClientKeysExplainTheRequirementWithoutConsumingReplayState(): void
    {
        [$client, $privateKey] = $this->assertionClient();
        $client->jwks = ['keys' => [[
            'kty' => 'RSA', 'e' => 'AQAB',
            'n' => JWT::urlsafeB64Encode("\x80" . random_bytes(511) . "\x01"),
        ]]];
        $replays = m::mock(ReplayCache::class);
        $replays->shouldNotReceive('consume');

        try {
            $this->authenticator($client, $replays)->authenticate($this->assertionRequest($privateKey));
            $this->fail('Unsupported client keys must be rejected.');
        } catch (OAuthServerException $exception) {
            $this->assertSame([
                'error' => 'invalid_client',
                'error_description' => 'RSA keys must be between 2048 and 4096 bits.',
            ], $exception->getPayload());
            $this->assertSame(401, $exception->getHttpStatusCode());
        }
    }

    #[TestWith(['{}', 503])]
    #[TestWith(['{invalid', 200])]
    public function testRemoteKeyFailuresRejectAuthenticationWithoutConsumingReplayState(string $body, int $status): void
    {
        config([
            'passport' => require __DIR__ . '/../../src/passport/config/passport.php',
            'passport.cache.store' => 'array',
        ]);
        [$client, $privateKey] = $this->assertionClient();
        $client->jwks = null;
        $client->jwks_uri = 'https://keys.example/jwks';
        Http::fake(['https://keys.example/jwks' => Http::response($body, $status)]);
        $replays = m::mock(ReplayCache::class);
        $replays->shouldNotReceive('consume');
        $authenticator = $this->authenticator($client, $replays, keys: $this->app->make(ClientKeyRepository::class));

        try {
            $authenticator->authenticate($this->assertionRequest($privateKey));
            $this->fail('An assertion without a usable remote key must be rejected.');
        } catch (OAuthServerException $exception) {
            $this->assertSame('invalid_client', $exception->getErrorType());
        }

        Http::assertSentCount(1);
    }

    public function testServerDestinationPolicyErrorsAreNotHiddenAsInvalidCredentials(): void
    {
        [$client, $privateKey] = $this->assertionClient();
        $failure = new DestinationPolicyException('The HTTP transport cannot enforce the destination policy.');
        $keys = m::mock(ClientKeyRepository::class);
        $keys->shouldReceive('find')->once()->andThrow($failure);

        $this->expectExceptionObject($failure);
        $this->authenticator($client, keys: $keys)->authenticate($this->assertionRequest($privateKey));
    }

    /**
     * Build the authenticator with real key parsing and isolated repositories.
     */
    protected function authenticator(
        Client $client,
        ?ReplayCache $replays = null,
        ?string $issuer = 'https://auth.example',
        ?ClientKeyRepository $keys = null,
    ): ClientAuthenticator {
        $clients = m::mock(ClientRepository::class);
        $clients->shouldReceive('findActive')->with($client->getKey())->andReturn($client);
        $servers = m::mock(ServerFactory::class);
        $servers->shouldReceive('settings')->andReturn(new ServerSettings([], issuer: $issuer));
        $keys ??= new ClientKeyRepository(m::mock(Factory::class), new Repository, m::mock(RemoteDocumentFetcher::class), new JwkValidator);

        return new ClientAuthenticator($clients, $keys, $replays ?? m::mock(ReplayCache::class), $servers);
    }

    /**
     * Build an assertion client without a shared secret.
     */
    protected function assertionClient(): array
    {
        $material = (new SigningKeyFactory)->definition();
        $key = ParsedKey::fromPublicKey($material['public_key'])->getJwk();

        return [new Client([
            'id' => 'client', 'secret' => null, 'token_endpoint_auth_method' => 'private_key_jwt',
            'token_endpoint_auth_signing_alg' => 'RS256', 'jwks' => ['keys' => [$key]], 'jwks_uri' => null,
        ]), $material['private_key']];
    }

    /**
     * Sign a client assertion without a redundant body client identifier.
     */
    protected function assertionRequest(string $privateKey, array $claims = []): ServerRequest
    {
        $jwt = JWT::encode([
            'iss' => 'client', 'sub' => 'client', 'aud' => 'https://auth.example', 'jti' => 'assertion',
            'iat' => time(), 'exp' => time() + 120, ...$claims,
        ], $privateKey, 'RS256');

        return (new ServerRequest('POST', 'https://auth.example/oauth/token'))->withParsedBody([
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $jwt,
        ]);
    }
}
