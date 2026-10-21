<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature\Oauth2;

use DateTimeImmutable;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Exceptions\InvalidStateException;
use Hypervel\Saloon\Exceptions\OAuthConfigValidationException;
use Hypervel\Saloon\Exceptions\PendingRequestException;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Auth\AccessTokenAuthenticator;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\OAuth2\GetAccessTokenRequest;
use Hypervel\Saloon\Http\OAuth2\GetRefreshTokenRequest;
use Hypervel\Saloon\Http\OAuth2\GetUserRequest;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Authenticators\CustomOAuthAuthenticator;
use Hypervel\Tests\Saloon\Fixtures\Connectors\CustomRequestOAuth2Connector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\CustomResponseOAuth2Connector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\NoConfigAuthCodeConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\OAuth2Connector;
use Hypervel\Tests\Saloon\Fixtures\Requests\OAuth\CustomAccessTokenRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\OAuth\CustomOAuthUserRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\OAuth\CustomRefreshTokenRequest;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use UnexpectedValueException;

// authorizationUrl() returns the URL with its paired state, replacing getAuthorizationUrl() and getState(). Connectors
// take no mock client, so sends are faked through Saloon::fake(), and changed configuration is passed to the connector.
// Upstream's global clock is the framework Date clock.
class AuthCodeFlowConnectorTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testYouCanGetTheRedirectUrlFromAConnector(): void
    {
        $connector = new OAuth2Connector;

        $authorization = $connector->authorizationUrl(['scope-1', 'scope-2'], 'my-state');

        $this->assertSame('my-state', $authorization->state);

        $this->assertSame(
            'https://oauth.saloon.dev/authorize?response_type=code&scope=scope-1%20scope-2&client_id=client-id&redirect_uri=https%3A%2F%2Fmy-app.saloon.dev%2Fauth%2Fcallback&state=my-state',
            (string) $authorization,
        );
    }

    public function testYouCanProvideDefaultScopesThatWillBeAppliedToEveryAuthorizationUrl(): void
    {
        $connector = new OAuth2Connector(['defaultScopes' => ['scope-3']]);

        $url = (string) $connector->authorizationUrl(['scope-1', 'scope-2'], 'my-state');

        $this->assertSame(
            'https://oauth.saloon.dev/authorize?response_type=code&scope=scope-3%20scope-1%20scope-2&client_id=client-id&redirect_uri=https%3A%2F%2Fmy-app.saloon.dev%2Fauth%2Fcallback&state=my-state',
            $url,
        );
    }

    public function testYouCanGetAuthorizationUrlWithoutSettingValidScopes(): void
    {
        $connector = new OAuth2Connector(['defaultScopes' => ['', null]]);

        $url = (string) $connector->authorizationUrl([], 'my-state');

        $this->assertSame(
            'https://oauth.saloon.dev/authorize?response_type=code&client_id=client-id&redirect_uri=https%3A%2F%2Fmy-app.saloon.dev%2Fauth%2Fcallback&state=my-state',
            $url,
        );
    }

    public function testDefaultStateIsGeneratedAutomaticallyWithEveryAuthorizationUrlIfStateIsNotDefined(): void
    {
        $connector = new OAuth2Connector(['defaultScopes' => ['scope-3']]);

        $authorization = $connector->authorizationUrl(['scope-1', 'scope-2']);
        $state = $authorization->state;

        $this->assertNotSame('', $state);

        $this->assertStringEndsWith($state, (string) $authorization);
    }

    public function testAdditionalQueryParametersCanBeAddedPassedToTheAuthorizationUrl(): void
    {
        $connector = new OAuth2Connector;

        $url = (string) $connector->authorizationUrl(
            ['scope-1', 'scope-2'],
            state: 'my-state',
            additionalQueryParameters: ['another-param' => 'another-value', 'yee' => 'haw']
        );

        $this->assertStringEndsWith('another-param=another-value&yee=haw', $url);
        $this->assertSame(
            'https://oauth.saloon.dev/authorize?response_type=code&scope=scope-1%20scope-2&client_id=client-id&redirect_uri=https%3A%2F%2Fmy-app.saloon.dev%2Fauth%2Fcallback&state=my-state&another-param=another-value&yee=haw',
            $url,
        );
    }

    #[DataProvider('codeChallenges')]
    public function testAdditionalQueryParametersMayReplaceEverythingExceptTheState(?string $codeChallenge): void
    {
        $authorization = (new OAuth2Connector)->authorizationUrl(
            scopes: ['profile', '0'],
            state: 'known-state',
            additionalQueryParameters: [
                'response_type' => 'code id_token',
                'client_id' => 'replaced',
                'state' => 'replaced-state',
                'prompt' => '',
                'include_granted_scopes' => false,
            ],
            codeChallenge: $codeChallenge,
        );

        parse_str(parse_url((string) $authorization, PHP_URL_QUERY) ?: '', $query);

        $this->assertSame('known-state', $authorization->state);
        $this->assertSame('known-state', $query['state']);
        $this->assertSame('code id_token', $query['response_type']);
        $this->assertSame('replaced', $query['client_id']);
        $this->assertSame('profile 0', $query['scope']);
        $this->assertSame('', $query['prompt']);
        $this->assertSame('0', $query['include_granted_scopes']);
        $this->assertSame($codeChallenge, $query['code_challenge'] ?? null);
        $this->assertSame($codeChallenge === null ? null : 'S256', $query['code_challenge_method'] ?? null);
    }

    /**
     * Get authorization URLs with and without a PKCE challenge.
     *
     * @return array<string, array{?string}>
     */
    public static function codeChallenges(): array
    {
        return [
            'without PKCE' => [null],
            'with PKCE' => ['challenge'],
        ];
    }

    public function testAuthorizationUrlPreservesEndpointQueryAndGeneratesState(): void
    {
        $authorization = (new OAuth2Connector(['authorizeEndpoint' => 'oauth/authorize?audience=users']))->authorizationUrl();

        $this->assertNotSame('', $authorization->state);
        $this->assertStringContainsString('audience=users&response_type=code', (string) $authorization);
        $this->assertStringContainsString('state=' . $authorization->state, (string) $authorization);
    }

    public function testAuthorizationUrlReplacesProtocolParametersFromBaseAndEndpointQueries(): void
    {
        $authorization = (new BaseQueryOAuth2Connector(['authorizeEndpoint' => 'oauth/authorize?client_id=endpoint&audience=users']))
            ->authorizationUrl(state: 'known-state');
        $queryString = parse_url((string) $authorization, PHP_URL_QUERY) ?: '';
        parse_str($queryString, $query);

        $this->assertSame(1, substr_count($queryString, 'client_id='));
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('value', $query['base']);
        $this->assertSame('users', $query['audience']);
    }

    public function testInvalidPkceConfigurationIsRejectedBeforeSending(): void
    {
        $connector = new OAuth2Connector;

        foreach ([['', 'S256'], ['challenge', 'unsupported']] as [$challenge, $method]) {
            try {
                $connector->authorizationUrl(codeChallenge: $challenge, codeChallengeMethod: $method);
                $this->fail('An invalid PKCE configuration was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testYouCanRequestATokenFromAConnector(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600], 200),
        ]);

        $connector = new OAuth2Connector;

        Saloon::fake($mockClient);

        $authenticator = $connector->getAccessToken('code');

        $this->assertInstanceOf(AccessTokenAuthenticator::class, $authenticator);
        $this->assertSame('access', $authenticator->getAccessToken());
        $this->assertSame('refresh', $authenticator->getRefreshToken());
        $this->assertInstanceOf(DateTimeImmutable::class, $authenticator->getExpiresAt());
    }

    public function testOauthAccessTokensDeriveExpiryFromTheGlobalClock(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600], 200),
        ]);

        Date::setTestNow($now);

        $connector = new OAuth2Connector;
        Saloon::fake($mockClient);

        $authenticator = $connector->getAccessToken('code');

        $this->assertEquals($now->modify('+3600 seconds'), $authenticator->getExpiresAt());
    }

    public function testExpiriesStayImmutableWhenDateCreatesMutableDates(): void
    {
        Date::use(Carbon::class);
        Date::setTestNow('2026-01-01T00:00:00+00:00');
        Saloon::fake([
            MockResponse::make(['access_token' => 'access', 'expires_in' => 3600]),
        ]);

        $expiresAt = (new OAuth2Connector)->getAccessToken('code')->getExpiresAt();

        $this->assertInstanceOf(CarbonImmutable::class, $expiresAt);
        $this->assertEquals(new DateTimeImmutable('2026-01-01T01:00:00+00:00'), $expiresAt);
        $this->assertEquals(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), Date::now());
    }

    public function testCustomOauthAuthenticatorsUseTheGlobalClockForExpirySemantics(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600], 200),
        ]);

        Date::setTestNow($now);

        $connector = new CustomResponseOAuth2Connector('hello');
        Saloon::fake($mockClient);

        $authenticator = $connector->getAccessToken('code');

        $this->assertInstanceOf(CustomOAuthAuthenticator::class, $authenticator);
        $this->assertEquals($now->modify('+3600 seconds'), $authenticator->getExpiresAt());
        $this->assertFalse($authenticator->hasExpired());
    }

    public function testYouCanTapIntoTheAccessTokenRequestAndModifyIt(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600], 200),
        ]);

        $connector = new OAuth2Connector;

        Saloon::fake($mockClient);

        $authenticator = $connector->getAccessToken('code', requestModifier: function (Request $request): void {
            $request->withQueryParameters(['yee' => 'haw']);
        });

        $this->assertInstanceOf(AccessTokenAuthenticator::class, $authenticator);
        $this->assertSame('access', $authenticator->getAccessToken());
        $this->assertSame('refresh', $authenticator->getRefreshToken());
        $this->assertInstanceOf(DateTimeImmutable::class, $authenticator->getExpiresAt());

        $mockClient->assertSentCount(1);

        $this->assertSame(['yee' => 'haw'], $mockClient->lastPendingRequest()->queryParameters());
    }

    #[DataProvider('pkceConnectors')]
    public function testTheCodeVerifierIsSentWithTheDefaultAndCustomTokenRequests(OAuth2Connector|CustomRequestOAuth2Connector $connector): void
    {
        $mockClient = Saloon::fake([
            MockResponse::make(['access_token' => 'access']),
        ]);

        $connector->getAccessToken('code', codeVerifier: 'verifier');

        $this->assertSame('verifier', $mockClient->lastPendingRequest()->body()['code_verifier']);
    }

    /**
     * Get connectors with the default and a custom access-token request resolver.
     *
     * @return array<string, array{CustomRequestOAuth2Connector|OAuth2Connector}>
     */
    public static function pkceConnectors(): array
    {
        return [
            'default resolver' => [new OAuth2Connector],
            'custom resolver' => [new CustomRequestOAuth2Connector],
        ];
    }

    public function testYouCanRequestTheOriginalResponseInsteadOfTheAuthenticatorOnTheCreateTokensMethod(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600]),
        ]);

        $connector = new OAuth2Connector;

        Saloon::fake($mockClient);

        $response = $connector->getAccessToken('code', null, null, true);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600], $response->json());
    }

    public function testReturningTheRawTokenResponseSkipsAuthenticatorValidation(): void
    {
        Saloon::fake([MockResponse::make(['provider_specific' => true])]);

        $response = (new OAuth2Connector)->getAccessToken('code', returnResponse: true);

        $this->assertSame(['provider_specific' => true], $response->json());
    }

    public function testAnInvalidRefreshTokenIsRejected(): void
    {
        Saloon::fake([MockResponse::make(['access_token' => 'access', 'refresh_token' => []])]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIs('The OAuth token response contains an invalid refresh token.');

        (new OAuth2Connector)->getAccessToken('code');
    }

    public function testItWillThrowAnExceptionIfStateIsInvalid(): void
    {
        $connector = new OAuth2Connector;

        $state = 'secret';
        $connector->authorizationUrl(['scope-1', 'scope-2'], $state);

        $this->expectException(InvalidStateException::class);
        $this->expectExceptionMessageIs('Invalid state.');

        $connector->getAccessToken('code', 'invalid', $state);
    }

    public function testStateValidationRequiresACompleteMatchingPair(): void
    {
        $connector = new OAuth2Connector;
        $mockClient = Saloon::fake([
            MockResponse::make(['access_token' => 'access']),
        ]);

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

        $this->assertSame('access', $connector->getAccessToken('code', 'state', 'state')->getAccessToken());
        $mockClient->assertSentCount(1);
    }

    public function testYouCanRefreshATokenFromAConnector(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access-new', 'refresh_token' => 'refresh-new', 'expires_in' => 3600]),
        ]);

        $connector = new OAuth2Connector;

        Saloon::fake($mockClient);

        $authenticator = new AccessTokenAuthenticator('access', 'refresh', Date::now()->addSeconds(3600)->toDateTimeImmutable());

        $newAuthenticator = $connector->refreshAccessToken($authenticator);

        $this->assertInstanceOf(AccessTokenAuthenticator::class, $newAuthenticator);
        $this->assertSame('access-new', $newAuthenticator->getAccessToken());
        $this->assertSame('refresh-new', $newAuthenticator->getRefreshToken());
        $this->assertInstanceOf(DateTimeImmutable::class, $newAuthenticator->getExpiresAt());
    }

    public function testYouCanTapIntoTheRefreshTokenRequest(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access-new', 'refresh_token' => 'refresh-new', 'expires_in' => 3600]),
        ]);

        $connector = new OAuth2Connector;

        Saloon::fake($mockClient);

        $authenticator = new AccessTokenAuthenticator('access', 'refresh', Date::now()->addSeconds(3600)->toDateTimeImmutable());

        $newAuthenticator = $connector->refreshAccessToken($authenticator, requestModifier: function (Request $request): void {
            $request->withQueryParameters(['yee' => 'haw']);
        });

        $this->assertInstanceOf(AccessTokenAuthenticator::class, $newAuthenticator);
        $this->assertSame('access-new', $newAuthenticator->getAccessToken());
        $this->assertSame('refresh-new', $newAuthenticator->getRefreshToken());
        $this->assertInstanceOf(DateTimeImmutable::class, $newAuthenticator->getExpiresAt());

        $mockClient->assertSentCount(1);

        $this->assertSame(['yee' => 'haw'], $mockClient->lastPendingRequest()->queryParameters());
    }

    public function testTheRefreshAccessTokenMethodThrowsAnExceptionIfYouProvideItAnAuthenticatorThatIsNotRefreshable(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access-new', 'refresh_token' => 'refresh-new', 'expires_in' => 3600]),
        ]);

        $connector = new OAuth2Connector;

        Saloon::fake($mockClient);

        $authenticator = new AccessTokenAuthenticator('access', null, Date::now()->addSeconds(3600)->toDateTimeImmutable());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The provided OAuthAuthenticator does not contain a refresh token.');

        $connector->refreshAccessToken($authenticator);
    }

    public function testYouCanRequestTheOriginalResponseInsteadOfTheAuthenticatorOnTheRefreshTokensMethod(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access-new', 'refresh_token' => 'refresh-new', 'expires_in' => 3600]),
        ]);

        $connector = new OAuth2Connector;

        Saloon::fake($mockClient);

        $authenticator = new AccessTokenAuthenticator('access', 'refresh', Date::now()->addSeconds(3600)->toDateTimeImmutable());

        $response = $connector->refreshAccessToken($authenticator, true);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(['access_token' => 'access-new', 'refresh_token' => 'refresh-new', 'expires_in' => 3600], $response->json());
    }

    #[DataProvider('refreshEndpoints')]
    public function testRefreshUsesTheConfiguredEndpoint(?string $endpoint, bool $allowOverride, string $expected): void
    {
        $mockClient = Saloon::fake([
            GetRefreshTokenRequest::class => function (PendingRequest $pendingRequest) use ($expected): MockResponse {
                $this->assertSame($expected, (string) $pendingRequest->uri());
                $this->assertSame([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => 'refresh',
                    'client_id' => 'client-id',
                    'client_secret' => 'client-secret',
                ], $pendingRequest->body());
                $this->assertSame('configured', $pendingRequest->headers()['X-OAuth']);
                $this->assertSame('per-call', $pendingRequest->headers()['X-Request']);

                return MockResponse::make(['access_token' => 'renewed']);
            },
        ]);
        $connector = new OAuth2Connector([
            'tokenEndpoint' => 'oauth/token',
            'refreshEndpoint' => $endpoint,
            'requestModifier' => fn (Request $request): Request => $request->withHeader('X-OAuth', 'configured'),
            'allowBaseUrlOverride' => $allowOverride,
        ]);

        $authenticator = $connector->refreshAccessToken(
            'refresh',
            requestModifier: fn (Request $request): Request => $request->withHeader('X-Request', 'per-call'),
        );

        $this->assertSame('renewed', $authenticator->getAccessToken());
        $this->assertSame('refresh', $authenticator->getRefreshToken());
        $mockClient->assertSentCount(1);
    }

    /**
     * Provide refresh endpoints and their resolved URLs.
     *
     * @return array<string, array{?string, bool, string}>
     */
    public static function refreshEndpoints(): array
    {
        return [
            'token endpoint default' => [null, false, 'https://oauth.saloon.dev/oauth/token'],
            'relative refresh endpoint' => ['oauth/refresh?audience=users', false, 'https://oauth.saloon.dev/oauth/refresh?audience=users'],
            'trusted absolute refresh endpoint' => ['https://oauth.example.net/refresh', true, 'https://oauth.example.net/refresh'],
        ];
    }

    public function testAbsoluteRefreshEndpointRequiresOverridePermission(): void
    {
        $mockClient = Saloon::fake([MockResponse::make(['access_token' => 'renewed'])]);
        $connector = new OAuth2Connector(['refreshEndpoint' => 'https://oauth.example.net/refresh']);

        $this->expectException(PendingRequestException::class);
        $this->expectExceptionMessageIsOrContains('The request endpoint cannot replace the connector base URL.');

        try {
            $connector->refreshAccessToken('refresh');
        } finally {
            $mockClient->assertNothingSent();
        }
    }

    // The user request has no body, so unlike upstream it sends no form Content-Type.
    public function testYouCanGetTheUserFromAnOauthConnector(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['user' => 'Sam']),
        ]);

        $connector = new OAuth2Connector;
        Saloon::fake($mockClient);

        $accessToken = new AccessTokenAuthenticator('access', 'refresh', Date::now()->addSeconds(3600)->toDateTimeImmutable());

        $response = $connector->getUser($accessToken);

        $this->assertInstanceOf(Response::class, $response);

        $pendingRequest = $response->pendingRequest();

        $this->assertSame([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer access',
        ], $pendingRequest->headers());
    }

    public function testYouCanTapIntoTheTheUserRequest(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['user' => 'Sam']),
        ]);

        $connector = new OAuth2Connector;
        Saloon::fake($mockClient);

        $accessToken = new AccessTokenAuthenticator('access', 'refresh', Date::now()->addSeconds(3600)->toDateTimeImmutable());

        $response = $connector->getUser($accessToken, function (Request $request): void {
            $request->withQueryParameters(['yee' => 'haw']);
        });

        $this->assertInstanceOf(Response::class, $response);

        $pendingRequest = $response->pendingRequest();

        $this->assertSame(['yee' => 'haw'], $pendingRequest->queryParameters());

        $this->assertSame([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer access',
        ], $pendingRequest->headers());
    }

    public function testYouCanCustomizeTheOauthAuthenticator(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access-new', 'refresh_token' => 'refresh-new', 'expires_in' => 3600]),
        ]);

        $customConnector = new CustomResponseOAuth2Connector('Howdy!');
        Saloon::fake($mockClient);

        $authenticator = $customConnector->getAccessToken('code');

        $this->assertInstanceOf(CustomOAuthAuthenticator::class, $authenticator);
        $this->assertSame('Howdy!', $authenticator->getGreeting());
    }

    public function testYouCanRegisterAGlobalRequestModifierThatIsCalledOnEveryStepOfTheOAuth2Process(): void
    {
        $mockClient = new MockClient([
            GetAccessTokenRequest::class => MockResponse::make(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600], 200),
            GetRefreshTokenRequest::class => MockResponse::make(['access_token' => 'access-new', 'refresh_token' => 'refresh-new', 'expires_in' => 3600]),
            GetUserRequest::class => MockResponse::make(['user' => 'Sam']),
        ]);

        $requests = [];

        $connector = new OAuth2Connector(['requestModifier' => function (Request $request) use (&$requests): void {
            $requests[] = $request::class;

            match ($request::class) {
                GetAccessTokenRequest::class => $request->withQueryParameters(['request' => 'access']),
                GetRefreshTokenRequest::class => $request->withQueryParameters(['request' => 'refresh']),
                GetUserRequest::class => $request->withQueryParameters(['request' => 'user']),
            };
        }]);

        Saloon::fake($mockClient);

        $authenticator = $connector->getAccessToken('code');

        $this->assertInstanceOf(AccessTokenAuthenticator::class, $authenticator);
        $this->assertSame('access', $authenticator->getAccessToken());
        $this->assertSame('refresh', $authenticator->getRefreshToken());
        $this->assertInstanceOf(DateTimeImmutable::class, $authenticator->getExpiresAt());
        $this->assertSame(['request' => 'access'], $mockClient->lastPendingRequest()->queryParameters());

        $newAuthenticator = $connector->refreshAccessToken($authenticator);

        $this->assertInstanceOf(AccessTokenAuthenticator::class, $newAuthenticator);
        $this->assertSame('access-new', $newAuthenticator->getAccessToken());
        $this->assertSame('refresh-new', $newAuthenticator->getRefreshToken());
        $this->assertInstanceOf(DateTimeImmutable::class, $newAuthenticator->getExpiresAt());
        $this->assertSame(['request' => 'refresh'], $mockClient->lastPendingRequest()->queryParameters());

        $response = $connector->getUser($newAuthenticator);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(['request' => 'user'], $mockClient->lastPendingRequest()->queryParameters());

        $pendingRequest = $response->pendingRequest();

        $this->assertSame([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer access-new',
        ], $pendingRequest->headers());

        $this->assertSame([
            GetAccessTokenRequest::class,
            GetRefreshTokenRequest::class,
            GetUserRequest::class,
        ], $requests);
    }

    public function testIfYouAttemptToUseTheAuthorizationCodeFlowWithoutAClientIdItWillThrowAnException(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access', 'expires_in' => 3600], 200),
        ]);

        $connector = new NoConfigAuthCodeConnector;
        Saloon::fake($mockClient);

        $this->expectException(OAuthConfigValidationException::class);
        $this->expectExceptionMessageIs('The Client ID is empty or has not been provided.');

        $connector->getAccessToken('code');
    }

    public function testIfYouAttemptToUseTheAuthorizationCodeFlowWithoutASecretItWillThrowAnException(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access', 'expires_in' => 3600], 200),
        ]);

        $connector = new NoConfigAuthCodeConnector(['clientId' => 'hello']);
        Saloon::fake($mockClient);

        $this->expectException(OAuthConfigValidationException::class);
        $this->expectExceptionMessageIs('The Client Secret is empty or has not been provided.');

        $connector->getAccessToken('code');
    }

    public function testIfYouAttemptToUseTheAuthorizationCodeFlowWithoutARedirectUriItWillThrowAnException(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access', 'expires_in' => 3600], 200),
        ]);

        $connector = new NoConfigAuthCodeConnector(['clientId' => 'hello', 'clientSecret' => 'secret']);
        Saloon::fake($mockClient);

        $this->expectException(OAuthConfigValidationException::class);
        $this->expectExceptionMessageIs('The Redirect URI is empty or has not been provided.');

        $connector->getAccessToken('code');
    }

    public function testOnTheConnectorYouCanOverwriteAllTheRequestClasses(): void
    {
        $mockClient = new MockClient([
            CustomAccessTokenRequest::class => MockResponse::make(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600], 200),
            CustomRefreshTokenRequest::class => MockResponse::make(['access_token' => 'access-new', 'refresh_token' => 'refresh-new', 'expires_in' => 3600]),
            CustomOAuthUserRequest::class => MockResponse::make(['user' => 'Sam']),
        ]);

        $connector = new CustomRequestOAuth2Connector;
        Saloon::fake($mockClient);

        $accessTokenResponse = $connector->getAccessToken('code', returnResponse: true);

        $this->assertInstanceOf(CustomAccessTokenRequest::class, $accessTokenResponse->request());

        $refreshTokenResponse = $connector->refreshAccessToken('howdy', returnResponse: true);

        $this->assertInstanceOf(CustomRefreshTokenRequest::class, $refreshTokenResponse->request());

        $userResponse = $connector->getUser(new AccessTokenAuthenticator('howdy', 'partner'));

        $this->assertInstanceOf(CustomOAuthUserRequest::class, $userResponse->request());
    }
}

class BaseQueryOAuth2Connector extends OAuth2Connector
{
    /**
     * Define a base URL with its own query parameters.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://oauth.saloon.dev?client_id=base&base=value';
    }
}
