<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\Auth\BasicAuthenticator;
use Hypervel\Saloon\Http\Auth\CertificateAuthenticator;
use Hypervel\Saloon\Http\Auth\CookieAuthenticator;
use Hypervel\Saloon\Http\Auth\HeaderAuthenticator;
use Hypervel\Saloon\Http\Auth\MultiAuthenticator;
use Hypervel\Saloon\Http\Auth\NullAuthenticator;
use Hypervel\Saloon\Http\Auth\QueryAuthenticator;
use Hypervel\Saloon\Http\Auth\TokenAuthenticator;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\DefaultAuthenticatorConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use PHPUnit\Framework\Attributes\DataProvider;

// Upstream's deprecated withTokenAuth(), withQueryAuth(), withHeaderAuth() and withCertificateAuth() helpers are not
// included, so those cases authenticate with the authenticator classes.
class AuthenticatesRequestsTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testYouCanAddBasicAuthToARequest(): void
    {
        $request = new UserRequest;
        $request->withBasicAuth('Sammyjo20', 'Cowboy1');

        $pendingRequest = (new TestConnector)->createPendingRequest($request);
        $headers = $pendingRequest->headers();

        $this->assertSame('Basic ' . base64_encode('Sammyjo20:Cowboy1'), $headers['Authorization']);
    }

    public function testYouCanAttachAnAuthorizationTokenToARequest(): void
    {
        $request = UserRequest::make()->withToken('Sammyjo20');

        $pendingRequest = (new TestConnector)->createPendingRequest($request);
        $headers = $pendingRequest->headers();

        $this->assertSame('Bearer Sammyjo20', $headers['Authorization']);
    }

    // Upstream's digest-type argument and its array-sender case are not included: digest is the only supported type,
    // and every request uses the HTTP client's Guzzle transport.
    public function testYouCanAddDigestAuthToARequest(): void
    {
        $request = new UserRequest;
        $request->withDigestAuth('Sammyjo20', 'Cowboy1');

        $pendingRequest = (new TestConnector)->createPendingRequest($request);

        $this->assertSame(['Sammyjo20', 'Cowboy1', 'digest'], $pendingRequest->transportAuthentication());
    }

    public function testYouCanAddATokenToAQueryParameter(): void
    {
        $request = UserRequest::make()->authenticate(new QueryAuthenticator('token', 'Sammyjo20'));

        $pendingRequest = (new TestConnector)->createPendingRequest($request);
        $query = $pendingRequest->queryParameters();

        $this->assertSame('Sammyjo20', $query['token']);
    }

    public function testYouCanAddAHeaderToARequest(): void
    {
        $request = UserRequest::make()->authenticate(new HeaderAuthenticator('Sammyjo20', 'X-Authorization'));

        $pendingRequest = (new TestConnector)->createPendingRequest($request);
        $headers = $pendingRequest->headers();

        $this->assertSame('Sammyjo20', $headers['X-Authorization']);
    }

    public function testYouCanAddACertificateToARequest(): void
    {
        $certPath = __DIR__ . '/certificate.cer';

        $requestA = UserRequest::make()->authenticate(new CertificateAuthenticator($certPath));

        $pendingRequestA = (new TestConnector)->createPendingRequest($requestA);

        $this->assertSame($certPath, $pendingRequestA->certificate());

        // Test with password

        $requestB = UserRequest::make()->authenticate(new CertificateAuthenticator($certPath, 'example'));

        $pendingRequestB = (new TestConnector)->createPendingRequest($requestB);

        $this->assertSame([$certPath, 'example'], $pendingRequestB->certificate());
    }

    public function testYouCanUseMultipleAuthenticatorsAtTheSameTimeUsingTheDefaultAuthMethod(): void
    {
        $request = UserRequest::make()->authenticate(new MultiAuthenticator(
            new TokenAuthenticator('example'),
            new HeaderAuthenticator('api-key', 'X-API-Key'),
        ));

        $pendingRequest = (new TestConnector)->createPendingRequest($request);

        $headers = $pendingRequest->headers();

        $this->assertSame([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer example',
            'X-API-Key' => 'api-key',
        ], $headers);
    }

    public function testYouCanUseANullAuthenticatorToDisableDefaultAuthenticationEntirely(): void
    {
        $connector = new DefaultAuthenticatorConnector;
        $request = new UserRequest;

        $request->authenticate(new NullAuthenticator);

        $pendingRequest = $connector->createPendingRequest($request);

        $this->assertSame(['Accept' => 'application/json'], $pendingRequest->headers());
    }

    #[DataProvider('authorizationHeaderOrders')]
    public function testTheLastAuthorizationHeaderReachesTheTransport(Authenticator $first, Authenticator $last, string $expected): void
    {
        $sent = [];
        Http::fake(function (HttpRequest $request) use (&$sent): PromiseInterface {
            $sent[] = $request->header('Authorization');

            return Factory::response();
        });

        (new TestConnector)->send(UserRequest::make()->authenticate(new MultiAuthenticator($first, $last)));

        $this->assertSame([[$expected]], $sent);
    }

    /**
     * Get authenticator pairs and the Authorization header the last one sets.
     *
     * @return array<string, array{Authenticator, Authenticator, string}>
     */
    public static function authorizationHeaderOrders(): array
    {
        $basic = 'Basic ' . base64_encode('Sammyjo20:Cowboy1');

        return [
            'basic then token' => [new BasicAuthenticator('Sammyjo20', 'Cowboy1'), new TokenAuthenticator('example'), 'Bearer example'],
            'token then basic' => [new TokenAuthenticator('example'), new BasicAuthenticator('Sammyjo20', 'Cowboy1'), $basic],
        ];
    }

    #[DataProvider('cookieDomains')]
    public function testCookieDomainIsInferredOrExplicit(?string $domain, string $expected, string $scheme): void
    {
        $pendingRequest = (new TestConnector($scheme . '://api.example.com'))->createPendingRequest(
            UserRequest::make()->authenticate(new CookieAuthenticator('session', 'secret', $domain)),
        );

        $this->assertCount(1, $pendingRequest->cookies());
        $cookie = $pendingRequest->cookies()[0];
        $this->assertSame('session', $cookie['Name']);
        $this->assertSame('secret', $cookie['Value']);
        $this->assertSame($expected, ltrim($cookie['Domain'], '.'));
        $this->assertSame($domain === null, $cookie['HostOnly'] ?? false);
        $this->assertSame($scheme === 'https', $cookie['Secure']);
        $this->assertTrue($cookie['Discard']);
        $this->assertTrue((new SetCookie($cookie))->matchesDomain('api.example.com'));
        $this->assertSame($domain !== null, (new SetCookie($cookie))->matchesDomain('other.example.com'));
    }

    /**
     * Provide inferred and explicitly scoped cookie domains.
     *
     * @return array<string, array{?string, string, string}>
     */
    public static function cookieDomains(): array
    {
        return [
            'inferred HTTPS' => [null, 'api.example.com', 'https'],
            'explicit HTTPS' => ['.example.com', 'example.com', 'https'],
            'inferred HTTP' => [null, 'api.example.com', 'http'],
            'explicit HTTP' => ['.example.com', 'example.com', 'http'],
        ];
    }

    public function testReplacementAuthenticationReachesTheTransportWithoutAccumulatingAcrossRetries(): void
    {
        $cookies = [];
        Http::fake(function (HttpRequest $request, array $options) use (&$cookies): PromiseInterface {
            $cookies[] = $options['cookies']->toArray();

            return Factory::response('', count($cookies) === 1 ? 500 : 200);
        });
        $request = UserRequest::make()
            ->authenticate(new CookieAuthenticator('session', 'original'))
            ->authenticate(new CookieAuthenticator('session', 'request'))
            ->retry(2);
        $pendingCookies = [];
        $request->middleware()->onRequest(function (PendingRequest $pendingRequest) use (&$pendingCookies): void {
            $pendingRequest->authenticate(new CookieAuthenticator('session', 'replacement'));
            $pendingCookies[] = $pendingRequest->cookies();
        });

        $response = (new TestConnector('https://api.example.com'))->send($request);

        $this->assertSame(200, $response->status());
        $this->assertCount(2, $cookies);
        foreach ($cookies as $attemptCookies) {
            $this->assertCount(1, $attemptCookies);
            $this->assertSame('session', $attemptCookies[0]['Name']);
            $this->assertSame('replacement', $attemptCookies[0]['Value']);
            $this->assertSame('api.example.com', $attemptCookies[0]['Domain']);
            $this->assertTrue($attemptCookies[0]['HostOnly']);
            $this->assertTrue($attemptCookies[0]['Secure']);
            $this->assertTrue($attemptCookies[0]['Discard']);
        }
        $this->assertSame($pendingCookies[0], $pendingCookies[1]);
        $this->assertCount(2, $pendingCookies[0]);
        $this->assertSame([], $request->cookies());
    }
}
