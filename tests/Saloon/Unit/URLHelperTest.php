<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use GuzzleHttp\Psr7\Uri;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Saloon\Exceptions\PendingRequestException;
use Hypervel\Saloon\Http\UrlResolver;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Collection;
use Hypervel\Support\Stringable;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\AbsoluteEndpointRequest;
use PHPUnit\Framework\Attributes\DataProvider;

// Upstream's URLHelper maps to UrlResolver, which joins and validates URLs and merges query parameters.
class URLHelperTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    #[DataProvider('urlsToJoin')]
    public function testTheUrlHelperWillJoinTwoUrlsTogether(string $baseUrl, string $endpoint, string $expected): void
    {
        $this->assertSame($expected, (string) UrlResolver::resolve($baseUrl, $endpoint, false));
    }

    /**
     * Get the base URLs and endpoints to join.
     *
     * Upstream's last case joins an empty base URL and a relative endpoint into "/google.com/search", which cannot
     * be sent, so it is rejected instead (see testEmptyBaseUrlRequiresAnAbsoluteHttpEndpoint).
     *
     * @return array<int, array{string, string, string}>
     */
    public static function urlsToJoin(): array
    {
        return [
            ['https://google.com', '/search', 'https://google.com/search'],
            ['https://google.com', 'search', 'https://google.com/search'],
            ['https://google.com/', '/search', 'https://google.com/search'],
            ['https://google.com/', 'search', 'https://google.com/search'],
            ['https://google.com//', '//search', 'https://google.com/search'],
        ];
    }

    public function testJoinThrowsWhenEndpointIsAnAbsoluteUrlToPreventSsrfAndCredentialLeakage(): void
    {
        $trustedBaseUrl = 'https://api.trusted.com';
        $attackerUrl = 'https://attacker.example.com/steal';

        $this->expectException(PendingRequestException::class);
        $this->expectExceptionMessageIsOrContains('The request endpoint cannot replace the connector base URL.');

        UrlResolver::resolve($trustedBaseUrl, $attackerUrl, false);
    }

    public function testJoinAllowsAbsoluteEndpointWhenAllowBaseUrlOverrideIsTrue(): void
    {
        $this->assertSame(
            'https://auth.provider.com/token',
            (string) UrlResolver::resolve('https://api.example.com', 'https://auth.provider.com/token', true),
        );
    }

    public function testJoinWithEmptyBaseAndAbsoluteEndpointIgnoresAllowBaseUrlOverrideFlag(): void
    {
        $this->assertSame(
            'https://solo.example.com/hook',
            (string) UrlResolver::resolve('', 'https://solo.example.com/hook', false),
        );
    }

    public function testCreatingAPendingRequestWithARequestThatReturnsAbsoluteUrlFromResolveEndpointThrows(): void
    {
        $connector = new TestConnector('https://api.trusted.com');
        $request = new AbsoluteEndpointRequest('https://attacker.example.com/callback');

        $this->expectException(PendingRequestException::class);
        $this->expectExceptionMessageIsOrContains('The request endpoint cannot replace the connector base URL.');

        // The URL is resolved when it is first read rather than when the pending request is created.
        $connector->createPendingRequest($request)->uri();
    }

    public function testConnectorAllowBaseUrlOverrideAllowsPendingRequestWithAbsoluteEndpoint(): void
    {
        $connector = new AllowsBaseUrlOverrideConnector('https://api.trusted.com');
        $request = new AbsoluteEndpointRequest('https://other-api.example.com/v1');

        $pending = $connector->createPendingRequest($request);

        $this->assertSame('https://other-api.example.com/v1', (string) $pending->uri());
    }

    public function testRequestAllowBaseUrlOverrideFalseDeniesAbsoluteEndpointEvenWhenConnectorAllows(): void
    {
        $connector = new AllowsBaseUrlOverrideConnector('https://api.trusted.com');
        $request = new AbsoluteEndpointRequest('https://other.example.com/x', false);

        $this->expectException(PendingRequestException::class);

        $connector->createPendingRequest($request)->uri();
    }

    // Upstream parses the existing URL query into an array and rebuilds it. Hypervel keeps the existing pairs as
    // written and only replaces the names being merged, so this dataset checks what is kept.
    #[DataProvider('existingQueries')]
    public function testTheUrlHelperCanParseAVarietyOfQueryParameters(string $query, string $expected): void
    {
        $uri = UrlResolver::withQuery(new Uri('https://api.example.com/users?' . $query), ['page' => 2]);

        $this->assertSame($expected, $uri->getQuery());
    }

    /**
     * Get existing URL queries and the result of merging a parameter into them.
     *
     * @return array<int, array{string, string}>
     */
    public static function existingQueries(): array
    {
        return [
            ['foo=bar', 'foo=bar&page=2'],
            ['foo=bar&name=sam', 'foo=bar&name=sam&page=2'],
            ['foo==bar&name=sam', 'foo==bar&name=sam&page=2'],
            ['=abc&name=sam', '=abc&name=sam&page=2'],
            ['foo&name=sam', 'foo&name=sam&page=2'],
            ['account.id=1', 'account.id=1&page=2'],
            ['name=cowboy%20sam', 'name=cowboy%20sam&page=2'],
            ['name=sam&', 'name=sam&page=2'],
            ['&name=sam&&page=1', 'name=sam&page=2'],
        ];
    }

    #[DataProvider('relativeEndpoints')]
    public function testEndpointsWithoutASchemeStayOnTheConnectorHost(string $endpoint, string $expected): void
    {
        $this->assertSame($expected, (string) UrlResolver::resolve('https://api.example.com/v1', $endpoint, false));
    }

    /**
     * Get endpoints that URI parsing would otherwise read as a scheme or a host.
     *
     * @return array<string, array{string, string}>
     */
    public static function relativeEndpoints(): array
    {
        return [
            'colon in the first segment' => ['documents:batchGet', 'https://api.example.com/v1/documents:batchGet'],
            'colon with a query' => ['users:search?q=sam', 'https://api.example.com/v1/users:search?q=sam'],
            'host-looking path' => ['//evil.example.com/steal', 'https://api.example.com/v1/evil.example.com/steal'],
            'scheme without slashes' => ['mailto:sam@example.com', 'https://api.example.com/v1/mailto:sam@example.com'],
        ];
    }

    public function testRelativeEndpointIsJoinedToTheConnectorBaseUrl(): void
    {
        $uri = UrlResolver::resolve('https://api.example.com/v1/', '/users?active=1', false);

        $this->assertSame('https://api.example.com/v1/users?active=1', (string) $uri);
    }

    public function testEmptyEndpointPreservesTheConnectorBasePath(): void
    {
        $this->assertSame(
            'https://api.example.com/v1',
            (string) UrlResolver::resolve('https://api.example.com/v1', '', false),
        );
        $this->assertSame(
            'https://api.example.com/v1/',
            (string) UrlResolver::resolve('https://api.example.com/v1', '/', false),
        );
    }

    public function testBaseAndEndpointQueriesAreRetainedBeforeRepositoryOverrides(): void
    {
        $this->assertSame(
            'https://api.example.com/v1/users?key=base&keep=base',
            (string) UrlResolver::resolve('https://api.example.com/v1?key=base&keep=base', '/users', false),
        );

        $uri = UrlResolver::resolve(
            'https://api.example.com/v1?key=base&keep=base',
            '/users?endpoint=value',
            false,
        );

        $this->assertSame('key=base&keep=base&endpoint=value', $uri->getQuery());
        $this->assertSame(
            'keep=base&endpoint=value&key=repository',
            UrlResolver::withQuery($uri, ['key' => 'repository'])->getQuery(),
        );
    }

    public function testEmptyBaseUrlRequiresAnAbsoluteHttpEndpoint(): void
    {
        $this->expectException(PendingRequestException::class);
        $this->expectExceptionMessageIs('A request without a connector base URL must use an absolute HTTP or HTTPS endpoint.');

        UrlResolver::resolve('', 'google.com/search', true);
    }

    #[DataProvider('alternateSchemeBaseUrls')]
    public function testAlternateSchemesAreRejected(string $baseUrl): void
    {
        $this->expectException(PendingRequestException::class);
        $this->expectExceptionMessageIs('The request endpoint must be an absolute HTTP or HTTPS URI.');

        UrlResolver::resolve($baseUrl, 'file:///etc/passwd', true);
    }

    /**
     * Get the base URLs an alternate scheme endpoint cannot replace.
     *
     * @return array<string, array{string}>
     */
    public static function alternateSchemeBaseUrls(): array
    {
        return [
            'without a base URL' => [''],
            'with a base URL' => ['https://api.example.com'],
        ];
    }

    public function testRepositoryQueryReplacesExactAndNestedRawFamilies(): void
    {
        $uri = UrlResolver::resolve(
            'https://api.example.com',
            '/users?filter=old&filter%5Bname%5D=Taylor&keep=one&keep=two&flag',
            false,
        );

        $resolved = UrlResolver::withQuery($uri, [
            'filter' => ['name' => 'Abigail'],
            'page' => 2,
        ]);

        $this->assertSame(
            'keep=one&keep=two&flag&filter%5Bname%5D=Abigail&page=2',
            $resolved->getQuery(),
        );
    }

    public function testNullAndEmptyArrayValuesRemoveMatchingRawFamilies(): void
    {
        $uri = UrlResolver::resolve(
            'https://api.example.com',
            '/users?remove=one&remove%5Bnested%5D=two&empty%5B0%5D=value&keep=yes',
            false,
        );

        $resolved = UrlResolver::withQuery($uri, ['remove' => null, 'empty' => []]);

        $this->assertSame('keep=yes', $resolved->getQuery());
    }

    public function testDecodedNamesUseQueryStringSemanticsWithoutNormalizingRetainedPairs(): void
    {
        $uri = UrlResolver::resolve(
            'https://api.example.com',
            '/users?first+name=old&retained=a+b&literal=%7Bvalue%7D',
            false,
        );

        $resolved = UrlResolver::withQuery($uri, ['first name' => 'Taylor']);

        $this->assertSame('retained=a+b&literal=%7Bvalue%7D&first%20name=Taylor', $resolved->getQuery());
    }

    public function testStructuredValuesAreNormalized(): void
    {
        $uri = UrlResolver::resolve('https://api.example.com', '/users', false);

        $resolved = UrlResolver::withQuery($uri, [
            'collection' => new Collection(['one', 'two']),
            'stringable' => new Stringable('value'),
            'not-a-number' => NAN,
        ]);

        $this->assertSame(
            'collection%5B0%5D=one&collection%5B1%5D=two&stringable=value&not-a-number=NAN',
            $resolved->getQuery(),
        );
    }
}

// Upstream sets the public $allowBaseUrlOverride property on a connector instance. Hypervel connectors are
// read-only and declare it by overriding allowsBaseUrlOverride().
class AllowsBaseUrlOverrideConnector extends TestConnector
{
    /**
     * Resolve whether requests may replace this connector's base URL.
     */
    public function allowsBaseUrlOverride(): bool
    {
        return true;
    }
}
