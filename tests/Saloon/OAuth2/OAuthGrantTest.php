<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\OAuth2;

use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Client\Factory;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Exceptions\InvalidStateException;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\OAuth2\GetAccessTokenRequest;
use Hypervel\Saloon\Http\OAuth2\GetClientCredentialsTokenBasicAuthRequest;
use Hypervel\Saloon\Http\OAuth2\GetClientCredentialsTokenRequest;
use Hypervel\Saloon\Http\OAuth2\GetRefreshTokenRequest;
use Hypervel\Saloon\Http\OAuth2\GetUserRequest;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Http\Sender;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Saloon\Traits\OAuth2\AuthorizationCodeGrant;
use Hypervel\Saloon\Traits\OAuth2\ClientCredentialsBasicAuthGrant;
use Hypervel\Saloon\Traits\OAuth2\ClientCredentialsGrant;
use Hypervel\Support\Facades\Date;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use UnexpectedValueException;

class OAuthGrantTest extends TestCase
{
    public function testAuthorizationUrlKeepsStatePairedAndProtectsProtocolParameters(): void
    {
        $connector = new AuthorizationCodeConnectorStub($this->manager(), $this->config());

        $authorization = $connector->authorizationUrl(
            scopes: ['profile'],
            state: 'known-state',
            additionalQueryParameters: [
                'client_id' => 'attacker',
                'state' => 'attacker-state',
                'prompt' => '',
                'include_granted_scopes' => false,
            ],
            codeChallenge: 'challenge',
        );

        parse_str(parse_url((string) $authorization, PHP_URL_QUERY) ?: '', $query);

        $this->assertSame('known-state', $authorization->state);
        $this->assertSame('client', $query['client_id']);
        $this->assertSame('known-state', $query['state']);
        $this->assertSame('openid profile', $query['scope']);
        $this->assertSame('', $query['prompt']);
        $this->assertSame('0', $query['include_granted_scopes']);
        $this->assertSame('challenge', $query['code_challenge']);
        $this->assertSame('S256', $query['code_challenge_method']);
    }

    public function testAuthorizationUrlPreservesEndpointQueryAndGeneratesState(): void
    {
        $config = new OAuthConfig(
            clientId: 'client',
            clientSecret: 'secret',
            redirectUri: 'https://app.example.com/callback',
            authorizeEndpoint: 'oauth/authorize?audience=users',
        );

        $authorization = (new AuthorizationCodeConnectorStub($this->manager(), $config))->authorizationUrl();

        $this->assertNotSame('', $authorization->state);
        $this->assertStringContainsString('audience=users&response_type=code', (string) $authorization);
        $this->assertStringContainsString('state=' . $authorization->state, (string) $authorization);
    }

    public function testAuthorizationUrlReplacesProtocolParametersFromBaseAndEndpointQueries(): void
    {
        $config = new OAuthConfig(
            clientId: 'client',
            clientSecret: 'secret',
            redirectUri: 'https://app.example.com/callback',
            authorizeEndpoint: 'oauth/authorize?client_id=endpoint&audience=users',
        );
        $authorization = (new BaseQueryAuthorizationCodeConnectorStub($this->manager(), $config))
            ->authorizationUrl(state: 'known-state');
        $queryString = parse_url((string) $authorization, PHP_URL_QUERY) ?: '';
        parse_str($queryString, $query);

        $this->assertSame(1, substr_count($queryString, 'client_id='));
        $this->assertSame('client', $query['client_id']);
        $this->assertSame('value', $query['base']);
        $this->assertSame('users', $query['audience']);
    }

    public function testStateValidationRequiresACompleteMatchingPair(): void
    {
        $connector = new AuthorizationCodeConnectorStub($this->manager(), $this->config());

        foreach ([
            ['returned', null],
            [null, 'expected'],
            ['', 'expected'],
            ['returned', ''],
            ['returned', 'expected'],
        ] as [$returnedState, $expectedState]) {
            try {
                $connector->getAccessToken('code', $returnedState, $expectedState);
                $this->fail('Invalid state was accepted.');
            } catch (InvalidStateException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAuthorizationCodeGrantSendsTokenRefreshAndUserRequests(): void
    {
        Date::setTestNow('2026-08-10 12:00:00');

        try {
            $manager = $this->manager();
            $mockClient = $manager->fake([
                GetAccessTokenRequest::class => function (PendingRequest $pendingRequest): MockResponse {
                    $this->assertSame('code', $pendingRequest->body()['code']);
                    $this->assertSame('verifier', $pendingRequest->body()['code_verifier']);
                    $this->assertSame('configured', $pendingRequest->headers()['X-OAuth']);
                    $this->assertSame('per-call', $pendingRequest->headers()['X-Request']);

                    return MockResponse::make([
                        'access_token' => 'access',
                        'refresh_token' => 'refresh',
                        'expires_in' => '3600',
                    ]);
                },
                GetRefreshTokenRequest::class => function (PendingRequest $pendingRequest): MockResponse {
                    $this->assertSame('refresh', $pendingRequest->body()['refresh_token']);

                    return MockResponse::make(['access_token' => 'renewed']);
                },
                GetUserRequest::class => function (PendingRequest $pendingRequest): MockResponse {
                    $this->assertSame('Bearer renewed', $pendingRequest->headers()['Authorization']);

                    return MockResponse::make(['id' => 1]);
                },
            ]);
            $connector = new AuthorizationCodeConnectorStub($manager, $this->config(
                requestModifier: fn (Request $request) => $request->withHeader('X-OAuth', 'configured'),
            ));

            $authenticator = $connector->getAccessToken(
                code: 'code',
                state: 'state',
                expectedState: 'state',
                requestModifier: fn (Request $request) => $request->withHeader('X-Request', 'per-call'),
                codeVerifier: 'verifier',
            );
            $renewed = $connector->refreshAccessToken($authenticator);
            $user = $connector->getUser($renewed);

            $this->assertSame('access', $authenticator->getAccessToken());
            $this->assertSame('refresh', $authenticator->getRefreshToken());
            $this->assertSame(Date::now()->addHour()->getTimestamp(), $authenticator->getExpiresAt()?->getTimestamp());
            $this->assertSame('renewed', $renewed->getAccessToken());
            $this->assertSame('refresh', $renewed->getRefreshToken());
            $this->assertSame(['id' => 1], $user->json());
            $mockClient->assertSentCount(3);
        } finally {
            Date::setTestNow();
        }
    }

    public function testReturningTheRawTokenResponseSkipsAuthenticatorValidation(): void
    {
        $manager = $this->manager();
        $manager->fake([MockResponse::make(['provider_specific' => true])]);
        $connector = new AuthorizationCodeConnectorStub($manager, $this->config());

        $response = $connector->getAccessToken('code', returnResponse: true);

        $this->assertSame(['provider_specific' => true], $response->json());
    }

    public function testClientCredentialsGrantsUseTheirExpectedAuthenticationShape(): void
    {
        $manager = $this->manager();
        $mockClient = $manager->fake([
            GetClientCredentialsTokenRequest::class => function (PendingRequest $pendingRequest): MockResponse {
                $this->assertSame('client', $pendingRequest->body()['client_id']);
                $this->assertNull($pendingRequest->transportAuthentication());

                return MockResponse::make(['access_token' => 'body-token']);
            },
            GetClientCredentialsTokenBasicAuthRequest::class => function (PendingRequest $pendingRequest): MockResponse {
                $this->assertArrayNotHasKey('client_id', $pendingRequest->body());
                $this->assertSame(['client', 'secret'], $pendingRequest->transportAuthentication());

                return MockResponse::make(['access_token' => 'basic-token']);
            },
        ]);

        $bodyAuthenticator = (new ClientCredentialsConnectorStub($manager, $this->config()))
            ->getAccessToken(['profile']);
        $basicAuthenticator = (new ClientCredentialsBasicAuthConnectorStub($manager, $this->config()))
            ->getAccessToken(['profile']);

        $this->assertSame('body-token', $bodyAuthenticator->getAccessToken());
        $this->assertSame('basic-token', $basicAuthenticator->getAccessToken());
        $mockClient->assertSentCount(2);
    }

    public function testTokenResponseValidationRejectsInvalidValues(): void
    {
        Date::setTestNow('2026-08-10 12:00:00');

        try {
            foreach ([
                [],
                ['access_token' => ''],
                ['access_token' => 123],
                ['access_token' => 'token', 'refresh_token' => []],
                ['access_token' => 'token', 'expires_in' => -1],
                ['access_token' => 'token', 'expires_in' => '1.5'],
                ['access_token' => 'token', 'expires_in' => 1.0E+30],
                ['access_token' => 'token', 'expires_in' => PHP_INT_MAX],
            ] as $body) {
                $manager = $this->manager();
                $manager->fake([MockResponse::make($body)]);

                try {
                    (new ClientCredentialsConnectorStub($manager, $this->config()))->getAccessToken();
                    $this->fail('An invalid OAuth token response was accepted.');
                } catch (UnexpectedValueException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            Date::setTestNow();
        }
    }

    public function testTokenExpiryAcceptsTheExactRepresentableBoundary(): void
    {
        Date::setTestNow('2026-08-10 12:00:00');

        try {
            $expiresIn = PHP_INT_MAX - Date::now()->getTimestamp();
            $manager = $this->manager();
            $manager->fake([MockResponse::make([
                'access_token' => 'token',
                'expires_in' => $expiresIn,
            ])]);

            $authenticator = (new ClientCredentialsConnectorStub($manager, $this->config()))->getAccessToken();

            $this->assertSame(PHP_INT_MAX, $authenticator->getExpiresAt()?->getTimestamp());
            $this->assertTrue($authenticator->hasNotExpired());
        } finally {
            Date::setTestNow();
        }
    }

    public function testInvalidPkceConfigurationIsRejectedBeforeSending(): void
    {
        $connector = new AuthorizationCodeConnectorStub($this->manager(), $this->config());

        foreach ([['', 'S256'], ['challenge', 'unsupported']] as [$challenge, $method]) {
            try {
                $connector->authorizationUrl(codeChallenge: $challenge, codeChallengeMethod: $method);
                $this->fail('An invalid PKCE configuration was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Create an OAuth configuration.
     */
    protected function config(?callable $requestModifier = null): OAuthConfig
    {
        return new OAuthConfig(
            clientId: 'client',
            clientSecret: 'secret',
            redirectUri: 'https://app.example.com/callback',
            defaultScopes: ['openid'],
            requestModifier: $requestModifier,
        );
    }

    /**
     * Create the manager with isolated framework services.
     */
    protected function manager(): SaloonManager
    {
        $http = new Factory;
        $http->registerConnection('saloon');
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('string')
            ->with('saloon.connection.name')
            ->andReturn('saloon');

        return new SaloonManager(
            new Sender($http, $config),
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
            $config,
            new Dispatcher,
        );
    }
}

abstract class OAuthConnectorStub extends Connector
{
    public function __construct(
        protected SaloonManager $manager,
        protected readonly OAuthConfig $config,
    ) {
    }

    public function resolveBaseUrl(): string
    {
        return 'https://provider.example.com';
    }

    public function send(Request $request, ?MockClient $mockClient = null): Response
    {
        return $this->manager->send($this, $request, $mockClient);
    }

    protected function defaultOAuthConfig(): OAuthConfig
    {
        return $this->config;
    }
}

class AuthorizationCodeConnectorStub extends OAuthConnectorStub
{
    use AuthorizationCodeGrant;
}

class BaseQueryAuthorizationCodeConnectorStub extends AuthorizationCodeConnectorStub
{
    public function resolveBaseUrl(): string
    {
        return 'https://provider.example.com?client_id=base&base=value';
    }
}

class ClientCredentialsConnectorStub extends OAuthConnectorStub
{
    use ClientCredentialsGrant;
}

class ClientCredentialsBasicAuthConnectorStub extends OAuthConnectorStub
{
    use ClientCredentialsBasicAuthGrant;
}
