<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Http\ResponseHeaderBag;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ResponsePerformanceOptimizationTest extends TestCase
{
    #[DataProvider('commonResponsePreparationProvider')]
    public function testCommonResponsePreparationMatchesSymfony(array $headers): void
    {
        $request = Request::create('/', 'GET', server: ['SERVER_PROTOCOL' => 'HTTP/1.1']);
        $expected = new SymfonyResponse('content', 200, $headers);
        $actual = new Response('content', 200, $headers);

        $expected->prepare($request);
        $actual->prepare($request);

        $expectedHeaders = $expected->headers->all();
        $actualHeaders = $actual->headers->all();
        ksort($expectedHeaders);
        ksort($actualHeaders);

        $this->assertSame($expected->getContent(), $actual->getContent());
        $this->assertSame($expected->getProtocolVersion(), $actual->getProtocolVersion());
        $this->assertSame($expectedHeaders, $actualHeaders);
    }

    public static function commonResponsePreparationProvider(): array
    {
        return [
            'text content type' => [['Content-Type' => 'text/plain']],
            'text content type with charset' => [['Content-Type' => 'text/html; charset=UTF-16']],
            'non-text content type' => [['Content-Type' => 'application/json']],
            'transfer encoding removes length' => [[
                'Content-Type' => 'text/plain',
                'Content-Length' => '7',
                'Transfer-Encoding' => 'chunked',
            ]],
            'content disposition' => [[
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="file.txt"',
            ]],
        ];
    }

    public function testPrototypeDateIsRefreshedAndAnExplicitDateStillWins(): void
    {
        Response::flushState();
        new Response;

        $prototype = (new ReflectionProperty(Response::class, 'headerPrototype'))->getValue();
        $this->assertInstanceOf(ResponseHeaderBag::class, $prototype);
        $prototype->set('Date', 'Thu, 01 Jan 1970 00:00:00 GMT');
        (new ReflectionProperty(Response::class, 'headerPrototypeTimestamp'))->setValue(null, 0);

        $response = new Response;
        $date = $response->headers->get('Date');
        $this->assertNotNull($date);
        $timestamp = strtotime($date);
        $this->assertNotFalse($timestamp);

        $this->assertLessThanOrEqual(1, abs(time() - $timestamp));
        $this->assertSame(
            'Thu, 01 Jan 1970 00:00:00 GMT',
            (new Response(headers: ['Date' => 'Thu, 01 Jan 1970 00:00:00 GMT']))->headers->get('Date'),
        );
    }
}
