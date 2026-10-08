<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature\Oauth2;

use DateTimeImmutable;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Exceptions\OAuthConfigValidationException;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Auth\AccessTokenAuthenticator;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Date;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Authenticators\CustomOAuthAuthenticator;
use Hypervel\Tests\Saloon\Fixtures\Connectors\ClientCredentialsBasicAuthConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\ClientCredentialsConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\CustomRequestClientCredentialsConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\NoConfigClientCredentialsConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\OAuth\CustomClientCredentialsAccessTokenRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use UnexpectedValueException;

// Connectors take no mock client, so sends are faked through Saloon::fake(), and changed configuration is passed to
// the connector. Upstream's global clock is the framework Date clock.
class ClientCredentialsFlowConnectorTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testYouCanGetTheAuthenticatorFromTheConnector(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access', 'expires_in' => 3600], 200),
        ]);

        $connector = new ClientCredentialsConnector;
        Saloon::fake($mockClient);

        $authenticator = $connector->getAccessToken();

        $this->assertInstanceOf(AccessTokenAuthenticator::class, $authenticator);
        $this->assertSame('access', $authenticator->getAccessToken());
        $this->assertNull($authenticator->getRefreshToken());
        $this->assertFalse($authenticator->isRefreshable());
        $this->assertInstanceOf(DateTimeImmutable::class, $authenticator->getExpiresAt());

        $mockClient->assertSentCount(1);

        $this->assertSame([
            'grant_type' => 'client_credentials',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'scope' => '',
        ], $mockClient->lastPendingRequest()->body());
    }

    public function testClientCredentialsTokensDeriveExpiryFromTheGlobalClock(): void
    {
        $now = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access', 'expires_in' => 3600], 200),
        ]);

        Date::setTestNow($now);

        $connector = new ClientCredentialsConnector;
        Saloon::fake($mockClient);

        $authenticator = $connector->getAccessToken();

        $this->assertEquals($now->modify('+3600 seconds'), $authenticator->getExpiresAt());
    }

    public function testYouCanGetTheResponseInsteadOfTheAuthenticator(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access', 'expires_in' => 3600], 200),
        ]);

        $connector = new ClientCredentialsConnector;
        Saloon::fake($mockClient);

        $response = $connector->getAccessToken(returnResponse: true);

        $this->assertInstanceOf(Response::class, $response);

        $this->assertSame([
            'access_token' => 'access',
            'expires_in' => 3600,
        ], $response->json());
    }

    public function testYouCanTapIntoTheTokenRequest(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access', 'expires_in' => 3600], 200),
        ]);

        $connector = new ClientCredentialsConnector;
        Saloon::fake($mockClient);

        $authenticator = $connector->getAccessToken(requestModifier: function (Request $request): void {
            $request->withQueryParameters(['yee' => 'haw']);
        });

        $this->assertInstanceOf(AccessTokenAuthenticator::class, $authenticator);
        $this->assertSame('access', $authenticator->getAccessToken());
        $this->assertNull($authenticator->getRefreshToken());
        $this->assertFalse($authenticator->isRefreshable());
        $this->assertInstanceOf(DateTimeImmutable::class, $authenticator->getExpiresAt());

        $mockClient->assertSentCount(1);

        $this->assertSame(['yee' => 'haw'], $mockClient->lastPendingRequest()->queryParameters());
    }

    public function testYouCanSendScopesWithTheTokenRequest(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access', 'expires_in' => 3600], 200),
        ]);

        $connector = new ClientCredentialsConnector;
        Saloon::fake($mockClient);

        $authenticator = $connector->getAccessToken(['offline_access', 'clients', 'billing']);

        $this->assertInstanceOf(AccessTokenAuthenticator::class, $authenticator);

        $mockClient->assertSentCount(1);

        $this->assertSame([
            'grant_type' => 'client_credentials',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'scope' => 'offline_access clients billing',
        ], $mockClient->lastPendingRequest()->body());
    }

    public function testDefaultScopesOnTheOauthConfigWillBeMergedInWithTheScopesOnTheTokenRequest(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access', 'expires_in' => 3600], 200),
        ]);

        $connector = new ClientCredentialsConnector(['defaultScopes' => ['compliance']]);
        Saloon::fake($mockClient);

        $authenticator = $connector->getAccessToken(['offline_access', 'clients', 'billing']);

        $this->assertInstanceOf(AccessTokenAuthenticator::class, $authenticator);

        $mockClient->assertSentCount(1);

        $this->assertSame([
            'grant_type' => 'client_credentials',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'scope' => 'compliance offline_access clients billing',
        ], $mockClient->lastPendingRequest()->body());
    }

    public function testTheScopeSeparatorCanBeCustomised(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access', 'expires_in' => 3600], 200),
        ]);

        $connector = new ClientCredentialsConnector(['defaultScopes' => ['compliance']]);
        Saloon::fake($mockClient);

        $authenticator = $connector->getAccessToken(['offline_access', 'clients', 'billing'], '+');

        $this->assertInstanceOf(AccessTokenAuthenticator::class, $authenticator);

        $mockClient->assertSentCount(1);

        $this->assertSame([
            'grant_type' => 'client_credentials',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'scope' => 'compliance+offline_access+clients+billing',
        ], $mockClient->lastPendingRequest()->body());
    }

    #[DataProvider('scopeConnectors')]
    public function testNullAndEmptyScopesAreIgnored(ClientCredentialsBasicAuthConnector|ClientCredentialsConnector $connector): void
    {
        $mockClient = Saloon::fake([
            MockResponse::make(['access_token' => 'access']),
        ]);

        $connector->getAccessToken(['', 'clients', null, '0']);

        $this->assertSame('clients 0', $mockClient->lastPendingRequest()->body()['scope']);
    }

    /**
     * Get connectors with the default and Basic authentication token requests.
     *
     * @return array<string, array{ClientCredentialsBasicAuthConnector|ClientCredentialsConnector}>
     */
    public static function scopeConnectors(): array
    {
        return [
            'default request' => [new ClientCredentialsConnector],
            'basic auth request' => [new ClientCredentialsBasicAuthConnector],
        ];
    }

    public function testIfYouAttemptToUseTheClientCredentialsFlowWithoutAClientIdItWillThrowAnException(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access', 'expires_in' => 3600], 200),
        ]);

        $connector = new NoConfigClientCredentialsConnector;
        Saloon::fake($mockClient);

        $this->expectException(OAuthConfigValidationException::class);
        $this->expectExceptionMessageIs('The Client ID is empty or has not been provided.');

        $connector->getAccessToken();
    }

    public function testIfYouAttemptToUseTheClientCredentialsFlowWithoutASecretItWillThrowAnException(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access', 'expires_in' => 3600], 200),
        ]);

        $connector = new NoConfigClientCredentialsConnector(['clientId' => 'hello']);
        Saloon::fake($mockClient);

        $this->expectException(OAuthConfigValidationException::class);
        $this->expectExceptionMessageIs('The Client Secret is empty or has not been provided.');

        $connector->getAccessToken();
    }

    public function testOnTheConnectorYouCanOverwriteTheGetAccessTokenRequest(): void
    {
        $mockClient = new MockClient([
            CustomClientCredentialsAccessTokenRequest::class => MockResponse::make(['access_token' => 'access', 'expires_in' => 3600], 200),
        ]);

        $connector = new CustomRequestClientCredentialsConnector;
        Saloon::fake($mockClient);

        $accessTokenResponse = $connector->getAccessToken(returnResponse: true);

        $this->assertInstanceOf(CustomClientCredentialsAccessTokenRequest::class, $accessTokenResponse->request());
    }

    public function testTheClientCredentialsGrantCanUseBasicAuth(): void
    {
        $mockClient = new MockClient([
            MockResponse::make(['access_token' => 'access', 'expires_in' => 3600], 200),
        ]);

        $connector = new ClientCredentialsBasicAuthConnector;
        Saloon::fake($mockClient);

        $authenticator = $connector->getAccessToken();

        $this->assertInstanceOf(AccessTokenAuthenticator::class, $authenticator);
        $this->assertSame('access', $authenticator->getAccessToken());
        $this->assertNull($authenticator->getRefreshToken());
        $this->assertFalse($authenticator->isRefreshable());
        $this->assertInstanceOf(DateTimeImmutable::class, $authenticator->getExpiresAt());

        $mockClient->assertSentCount(1);

        $this->assertSame([
            'grant_type' => 'client_credentials',
            'scope' => '',
        ], $mockClient->lastPendingRequest()->body());

        $this->assertSame(
            'Basic ' . base64_encode('client-id:client-secret'),
            $mockClient->lastPendingRequest()->headers()['Authorization'],
        );
    }

    public function testAReturnedRefreshTokenIsIgnored(): void
    {
        Saloon::fake([MockResponse::make(['access_token' => 'access', 'refresh_token' => []])]);

        $authenticator = (new ClientCredentialsConnector)->getAccessToken();

        $this->assertNull($authenticator->getRefreshToken());
    }

    public function testACustomAuthenticatorReceivesTheExpiry(): void
    {
        Date::setTestNow('2026-01-01T00:00:00+00:00');
        Saloon::fake([MockResponse::make(['access_token' => 'access', 'expires_in' => 3600])]);

        $authenticator = (new CustomAuthenticatorClientCredentialsConnector)->getAccessToken();

        $this->assertInstanceOf(CustomOAuthAuthenticator::class, $authenticator);
        $this->assertEquals(new DateTimeImmutable('2026-01-01T01:00:00+00:00'), $authenticator->getExpiresAt());
    }

    public function testTokenResponseValidationRejectsInvalidValues(): void
    {
        Date::setTestNow('2026-08-10 12:00:00');

        foreach ([
            [],
            ['access_token' => ''],
            ['access_token' => 123],
            ['access_token' => 'token', 'expires_in' => -1],
            ['access_token' => 'token', 'expires_in' => '1.5'],
            ['access_token' => 'token', 'expires_in' => 1.0E+30],
            ['access_token' => 'token', 'expires_in' => PHP_INT_MAX],
        ] as $body) {
            Saloon::fake([MockResponse::make($body)]);

            try {
                (new ClientCredentialsConnector)->getAccessToken();
                $this->fail('An invalid OAuth token response was accepted.');
            } catch (UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('validExpiries')]
    public function testTokenExpiryAcceptsWholeNumbersOfSecondsUpToTheRepresentableBoundary(int|float|string $expiresIn, int $seconds): void
    {
        Date::setTestNow('2026-08-10 12:00:00');
        Saloon::fake([MockResponse::make(['access_token' => 'token', 'expires_in' => $expiresIn])]);

        $authenticator = (new ClientCredentialsConnector)->getAccessToken();

        $this->assertSame(Date::now()->getTimestamp() + $seconds, $authenticator->getExpiresAt()?->getTimestamp());
        $this->assertTrue($authenticator->hasNotExpired());
    }

    /**
     * Get valid expiry durations and the seconds they represent.
     *
     * @return array<string, array{float|int|string, int}>
     */
    public static function validExpiries(): array
    {
        // The test clock is fixed at this timestamp, so the boundary is the largest duration that does not overflow.
        $boundary = PHP_INT_MAX - 1786363200;

        return [
            'integer' => [3600, 3600],
            'integer string' => ['3600', 3600],
            'whole float' => [3600.0, 3600],
            'representable boundary' => [$boundary, $boundary],
        ];
    }
}

class CustomAuthenticatorClientCredentialsConnector extends ClientCredentialsConnector
{
    /**
     * Create the OAuth authenticator with upstream's client-credentials signature.
     */
    protected function createOAuthAuthenticator(string $accessToken, ?DateTimeImmutable $expiresAt = null): OAuthAuthenticator
    {
        return new CustomOAuthAuthenticator($accessToken, 'Howdy!', null, $expiresAt);
    }
}
