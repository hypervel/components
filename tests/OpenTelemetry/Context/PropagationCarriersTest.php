<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Context;

use GuzzleHttp\Psr7\Request;
use Hypervel\OpenTelemetry\Context\HeaderBagGetter;
use Hypervel\OpenTelemetry\Context\PsrRequestHeadersSetter;
use Hypervel\OpenTelemetry\Context\ResponseHeadersSetter;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Response;

class PropagationCarriersTest extends TestCase
{
    public function testReadsCaseInsensitiveSingleAndMultipleHeaderValues(): void
    {
        $headers = new HeaderBag([
            'TraceParent' => 'parent',
            'Baggage' => ['one', 'two'],
        ]);
        $getter = new HeaderBagGetter;

        $this->assertSame(['traceparent', 'baggage'], $getter->keys($headers));
        $this->assertSame('parent', $getter->get($headers, 'TRACEPARENT'));
        $this->assertSame(['one', 'two'], $getter->getAll($headers, 'baggage'));
        $this->assertNull($getter->get($headers, 'missing'));
    }

    public function testWritesResponseHeadersWithoutAppendingDuplicateValues(): void
    {
        $response = new Response('', 200, ['Server-Timing' => 'old']);
        $setter = new ResponseHeadersSetter;

        $setter->set($response, 'Server-Timing', 'traceparent;desc="new"');

        $this->assertSame('traceparent;desc="new"', $response->headers->get('Server-Timing'));
        $this->assertSame(['traceparent;desc="new"'], $response->headers->all('Server-Timing'));
    }

    public function testReplacesHeadersOnImmutablePsrRequests(): void
    {
        $request = new Request('GET', 'https://example.test', ['Traceparent' => 'old']);

        (new PsrRequestHeadersSetter)->set($request, 'traceparent', 'new');

        $this->assertSame('new', $request->getHeaderLine('traceparent'));
        $this->assertSame(['new'], $request->getHeader('traceparent'));
    }

    public function testRejectsUnsupportedCarriers(): void
    {
        $getter = new HeaderBagGetter;

        try {
            $getter->get([], 'traceparent');
            $this->fail('An unsupported header carrier was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('array', $exception->getMessage());
        }

        $carrier = [];

        try {
            (new PsrRequestHeadersSetter)->set($carrier, 'traceparent', 'value');
            $this->fail('An unsupported PSR request carrier was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('array', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);

        (new ResponseHeadersSetter)->set($carrier, 'traceparent', 'value');
    }
}
