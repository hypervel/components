<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Middleware;

use Hypervel\Http\Middleware\SetCacheHeaders as Cache;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CacheTest extends TestCase
{
    public function testItCanGenerateDefinitionViaStaticMethod(): void
    {
        $signature = (string) Cache::using('max_age=120;no-transform;s_maxage=60;');
        $this->assertSame('Hypervel\Http\Middleware\SetCacheHeaders:max_age=120;no-transform;s_maxage=60;', $signature);

        $signature = (string) Cache::using('max_age=120;no-transform;s_maxage=60');
        $this->assertSame('Hypervel\Http\Middleware\SetCacheHeaders:max_age=120;no-transform;s_maxage=60', $signature);

        $signature = (string) Cache::using([
            'max_age=120',
            'no-transform',
            's_maxage=60',
            'etag' => true,
        ]);
        $this->assertSame('Hypervel\Http\Middleware\SetCacheHeaders:max_age=120;no-transform;s_maxage=60;etag', $signature);

        $signature = (string) Cache::using([
            'max_age' => 120,
            'no-transform',
            's_maxage' => '60',
        ]);
        $this->assertSame('Hypervel\Http\Middleware\SetCacheHeaders:max_age=120;no-transform;s_maxage=60', $signature);
    }

    public function testDoNotSetHeaderWhenMethodNotCacheable(): void
    {
        $request = new Request;
        $request->setMethod('PUT');

        $response = (new Cache)->handle($request, function () {
            return new Response('Hello Hypervel');
        }, 'max_age=120;s_maxage=60');

        $this->assertNull($response->getMaxAge());
    }

    public function testDoNotSetHeaderWhenNoContent(): void
    {
        $response = (new Cache)->handle(new Request, function () {
            return new Response;
        }, 'max_age=120;s_maxage=60');

        $this->assertNull($response->getMaxAge());
        $this->assertNull($response->getEtag());
    }

    public function testSetHeaderToFileResponseEvenWithNoContent(): void
    {
        $response = (new Cache)->handle(new Request, function () {
            $filePath = __DIR__ . '/../Fixtures/test.txt';

            return new BinaryFileResponse($filePath);
        }, 'max_age=120;s_maxage=60');

        $this->assertNotNull($response->getMaxAge());
    }

    public function testSetHeaderToDownloadResponseEvenWithNoContent(): void
    {
        $response = (new Cache)->handle(new Request, function () {
            return new StreamedResponse(function () {
                $filePath = __DIR__ . '/../Fixtures/test.txt';
                readfile($filePath);
            });
        }, 'max_age=120;s_maxage=60');

        $this->assertNotNull($response->getMaxAge());
    }

    public function testAddHeaders(): void
    {
        $response = (new Cache)->handle(new Request, function () {
            return new Response('some content');
        }, 'max_age=100;s_maxage=200;etag=ABC');

        $this->assertSame('"ABC"', $response->getEtag());
        $this->assertSame('max-age=100, public, s-maxage=200', $response->headers->get('Cache-Control'));
    }

    public function testAddCacheHeadersForMethodHead(): void
    {
        $request = new Request;
        $request->setMethod('HEAD');
        $response = (new Cache)->handle($request, function () {
            return new Response;
        }, 'max_age=120;s_maxage=60');

        $this->assertNotNull($response->getMaxAge());
    }

    public function testAddHeadersUsingArray(): void
    {
        $response = (new Cache)->handle(new Request, function () {
            return new Response('some content');
        }, ['max_age' => 100, 's_maxage' => 200, 'etag' => 'ABC']);

        $this->assertSame('"ABC"', $response->getEtag());
        $this->assertSame('max-age=100, public, s-maxage=200', $response->headers->get('Cache-Control'));
    }

    public function testGenerateEtag(): void
    {
        $response = (new Cache)->handle(new Request, function () {
            return new Response('some content');
        }, 'etag;max_age=100;s_maxage=200');

        $this->assertSame('"' . hash('xxh128', 'some content') . '"', $response->getEtag());
        $this->assertSame('max-age=100, public, s-maxage=200', $response->headers->get('Cache-Control'));
    }

    public function testDoesNotOverrideEtag(): void
    {
        $response = (new Cache)->handle(new Request, function () {
            return (new Response('some content'))->setEtag('XYZ');
        }, 'etag');

        $this->assertSame('"XYZ"', $response->getEtag());
    }

    public function testIsNotModified(): void
    {
        $request = new Request;
        $request->headers->set('If-None-Match', '"' . hash('xxh128', 'some content') . '"');

        $response = (new Cache)->handle($request, function () {
            return new Response('some content');
        }, 'etag;max_age=100;s_maxage=200');

        $this->assertSame(304, $response->getStatusCode());
    }

    public function testInvalidOption(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Cache)->handle(new Request, function () {
            return new Response('some content');
        }, 'invalid');
    }

    public function testLastModifiedUnixTime(): void
    {
        $time = time();

        $response = (new Cache)->handle(new Request, function () {
            return new Response('some content');
        }, "last_modified={$time}");

        $this->assertSame($time, $response->getLastModified()->getTimestamp());
    }

    public function testLastModifiedStringDate(): void
    {
        $birthdate = '1973-04-09 10:10:10';
        $response = (new Cache)->handle(new Request, function () {
            return new Response('some content');
        }, "last_modified={$birthdate}");

        $this->assertSame(CarbonImmutable::parse($birthdate)->getTimestamp(), $response->getLastModified()->getTimestamp());
    }

    public function testTrailingDelimiterIgnored(): void
    {
        $time = time();

        $response = (new Cache)->handle(new Request, function () {
            return new Response('some content');
        }, "last_modified={$time};");

        $this->assertSame($time, $response->getLastModified()->getTimestamp());
    }

    public function testItDoesNotSetEtagHeadersForBinaryContent(): void
    {
        $response = (new Cache)->handle(new Request, function () {
            return new BinaryFileResponse(__DIR__ . '/../Fixtures/test.txt');
        }, 'etag');

        $this->assertNull($response->getEtag());
    }

    public function testContentZeroIsCacheableAndReadOnce(): void
    {
        $response = (new Cache)->handle(new Request, function () {
            return new class('0') extends Response {
                public int $contentReads = 0;

                public function __construct(string $content)
                {
                    parent::__construct($content);
                }

                public function getContent(): string|false
                {
                    ++$this->contentReads;

                    return parent::getContent();
                }
            };
        }, 'max_age=120;s_maxage=60');

        $this->assertNotNull($response->getMaxAge());
        $this->assertSame(1, $response->contentReads);
    }
}
