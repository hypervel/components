<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpServer;

use Hypervel\Http\Response as HypervelResponse;
use Hypervel\HttpServer\ResponseBridge;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResponseBridgeTest extends TestCase
{
    public function testSendPlainResponse()
    {
        $response = new Response('Hello World', 200, ['X-Custom' => 'value']);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('status')->once()->with(200);
        $swooleResponse->shouldReceive('header')->atLeast()->once();
        $swooleResponse->shouldReceive('end')->once()->with('Hello World');

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testSendStatusCode()
    {
        $response = new Response('Not Found', 404);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('status')->once()->with(404);
        $swooleResponse->shouldReceive('header')->withAnyArgs();
        $swooleResponse->shouldReceive('end')->once()->with('Not Found');

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testSendHeaders()
    {
        $response = new Response('OK', 200, [
            'Content-Type' => 'application/json',
            'X-Request-Id' => 'abc-123',
        ]);
        $swooleResponse = $this->mockSwooleResponse();

        $sentHeaders = [];
        $swooleResponse->shouldReceive('status')->once()->with(200);
        $swooleResponse->shouldReceive('header')->andReturnUsing(function ($name, $value) use (&$sentHeaders) {
            $sentHeaders[$name] = $value;
            return true;
        });
        $swooleResponse->shouldReceive('end')->once();

        ResponseBridge::send($response, $swooleResponse);

        $this->assertSame('application/json', $sentHeaders['Content-Type'] ?? $sentHeaders['content-type'] ?? null);
        $this->assertSame('abc-123', $sentHeaders['X-Request-Id'] ?? null);
    }

    public function testSendCookies()
    {
        $response = new Response('OK', 200);
        $response->headers->setCookie(Cookie::create('session', 'abc123')->withPath('/')->withSecure(true));
        $swooleResponse = $this->mockSwooleResponse();

        $sentCookies = [];
        $swooleResponse->shouldReceive('status')->once();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturn(true);
        $swooleResponse->shouldReceive('cookie')->andReturnUsing(function (...$args) use (&$sentCookies) {
            $sentCookies[] = $args;
            return true;
        });
        $swooleResponse->shouldReceive('end')->once();

        ResponseBridge::send($response, $swooleResponse);

        $this->assertCount(1, $sentCookies);
        $this->assertSame('session', $sentCookies[0][0]);
        $this->assertSame('abc123', $sentCookies[0][1]);
    }

    public function testSendWithoutBodyForHeadRequest()
    {
        $response = new Response('This body should not be sent', 200, ['Content-Length' => '28']);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('status')->once()->with(200);
        $swooleResponse->shouldReceive('header')->withAnyArgs();
        // end() called with no body argument
        $swooleResponse->shouldReceive('end')->once()->withNoArgs();

        ResponseBridge::send($response, $swooleResponse, withBody: false);
    }

    public function testSendAlreadyStreamedResponse()
    {
        $response = new HypervelResponse('', 200);
        $response->markStreamed();
        $swooleResponse = $this->mockSwooleResponse();

        // Should only call end() — no status, headers, or body
        $swooleResponse->shouldNotReceive('status');
        $swooleResponse->shouldNotReceive('header');
        $swooleResponse->shouldReceive('end')->once()->withNoArgs();

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testSendNonStreamedHypervelResponse()
    {
        $response = new HypervelResponse('Normal content', 200);
        // isStreamed() is false by default
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('status')->once()->with(200);
        $swooleResponse->shouldReceive('header')->withAnyArgs();
        $swooleResponse->shouldReceive('end')->once()->with('Normal content');

        ResponseBridge::send($response, $swooleResponse);
    }

    public function testSendBinaryFileResponse()
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'file contents');

        try {
            $response = new BinaryFileResponse($tmpFile);
            $swooleResponse = $this->mockSwooleResponse();

            $swooleResponse->shouldReceive('status')->once();
            $swooleResponse->shouldReceive('header')->withAnyArgs();
            $swooleResponse->shouldReceive('sendfile')->once()->with($tmpFile);

            ResponseBridge::send($response, $swooleResponse);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testSendStreamedResponse()
    {
        $chunks = [];
        $response = new StreamedResponse(function () {
            echo 'chunk1';
            echo 'chunk2';
        });

        $swooleResponse = $this->mockSwooleResponse();
        $swooleResponse->shouldReceive('status')->once();
        $swooleResponse->shouldReceive('header')->withAnyArgs();
        $swooleResponse->shouldReceive('write')->andReturnUsing(function ($chunk) use (&$chunks) {
            $chunks[] = $chunk;
            return true;
        });
        $swooleResponse->shouldReceive('end')->once()->withNoArgs();

        ResponseBridge::send($response, $swooleResponse);

        // Chunks should have been streamed via write()
        $this->assertNotEmpty($chunks);
        $this->assertSame('chunk1chunk2', implode('', $chunks));
    }

    public function testStreamedResponseRemovesConflictingHeaders()
    {
        $response = new StreamedResponse(function () {
            echo 'data';
        });
        $response->headers->set('Content-Length', '4');
        $response->headers->set('Transfer-Encoding', 'chunked');

        $swooleResponse = $this->mockSwooleResponse();
        $sentHeaders = [];
        $swooleResponse->shouldReceive('status')->once();
        $swooleResponse->shouldReceive('header')->andReturnUsing(function ($name, $value) use (&$sentHeaders) {
            $sentHeaders[$name] = $value;
            return true;
        });
        $swooleResponse->shouldReceive('write')->andReturn(true);
        $swooleResponse->shouldReceive('end')->once();

        ResponseBridge::send($response, $swooleResponse);

        // Content-Length and Transfer-Encoding should NOT be in the sent headers
        $this->assertArrayNotHasKey('Content-Length', $sentHeaders);
        $this->assertArrayNotHasKey('Transfer-Encoding', $sentHeaders);
    }

    public function testStreamedResponseCleansUpOutputBufferOnException()
    {
        $response = new StreamedResponse(function () {
            echo 'partial';
            throw new RuntimeException('Stream error');
        });

        $swooleResponse = $this->mockSwooleResponse();
        $swooleResponse->shouldReceive('status')->once();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturn(true);
        $swooleResponse->shouldReceive('write')->andReturn(true);
        // end() should NOT be called — exception interrupts before end()
        $swooleResponse->shouldNotReceive('end');

        $levelBefore = ob_get_level();

        try {
            ResponseBridge::send($response, $swooleResponse);
            $this->fail('Expected RuntimeException to propagate');
        } catch (RuntimeException $exception) {
            $this->assertSame('Stream error', $exception->getMessage());
        }

        // OB level should be restored even after the exception
        $this->assertSame($levelBefore, ob_get_level());
    }

    public function testSendMultipleCookies()
    {
        $response = new Response('OK', 200);
        $response->headers->setCookie(Cookie::create('first', 'one'));
        $response->headers->setCookie(Cookie::create('second', 'two'));

        $swooleResponse = $this->mockSwooleResponse();
        $cookieNames = [];
        $swooleResponse->shouldReceive('status')->once();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturn(true);
        $swooleResponse->shouldReceive('cookie')->andReturnUsing(function ($name) use (&$cookieNames) {
            $cookieNames[] = $name;
            return true;
        });
        $swooleResponse->shouldReceive('end')->once();

        ResponseBridge::send($response, $swooleResponse);

        $this->assertContains('first', $cookieNames);
        $this->assertContains('second', $cookieNames);
    }

    public function testSendEmptyBodyResponse()
    {
        $response = new Response('', 204);
        $swooleResponse = $this->mockSwooleResponse();

        $swooleResponse->shouldReceive('status')->once()->with(204);
        $swooleResponse->shouldReceive('header')->withAnyArgs();
        $swooleResponse->shouldReceive('end')->once()->with('');

        ResponseBridge::send($response, $swooleResponse);
    }

    private function mockSwooleResponse(): SwooleResponse
    {
        return m::mock(SwooleResponse::class);
    }
}
