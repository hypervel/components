<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use Hypervel\Saloon\Exceptions\PendingRequestException;
use Hypervel\Saloon\Http\UrlResolver;
use Hypervel\Support\Collection;
use Hypervel\Support\Stringable;
use Hypervel\Tests\TestCase;

class UrlResolverTest extends TestCase
{
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

    public function testAbsoluteEndpointRequiresAnExplicitOverride(): void
    {
        $this->expectException(PendingRequestException::class);

        UrlResolver::resolve('https://api.example.com', 'https://uploads.example.com/files', false);
    }

    public function testAbsoluteHttpEndpointCanBeExplicitlyAllowed(): void
    {
        $uri = UrlResolver::resolve('https://api.example.com', 'https://uploads.example.com/files', true);

        $this->assertSame('https://uploads.example.com/files', (string) $uri);
    }

    public function testEmptyBaseUrlRequiresAnAbsoluteHttpEndpoint(): void
    {
        $this->expectException(PendingRequestException::class);

        UrlResolver::resolve('', '/users', true);
    }

    public function testAlternateSchemesAreRejected(): void
    {
        $this->expectException(PendingRequestException::class);

        UrlResolver::resolve('', 'file:///etc/passwd', true);
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
