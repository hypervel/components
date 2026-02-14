<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hyperf\HttpMessage\Stream\SwooleStream;
use Hypervel\HttpServer\Response as HttpServerResponse;
use Hyperf\View\RenderInterface;
use Hypervel\Container\Container;
use Hypervel\Context\Context;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Exceptions\FileNotFoundException;
use Hypervel\Http\Response;
use Hypervel\HttpMessage\Exceptions\RangeNotSatisfiableHttpException;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Swow\Psr7\Message\ResponsePlusInterface;
use Swow\Psr7\Message\ServerRequestPlusInterface;

/**
 * @internal
 * @coversNothing
 */
class ResponseTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::destroy(ResponseInterface::class);
        Context::destroy(Response::RANGE_HEADERS_CONTEXT_KEY);
        Context::destroy(ServerRequestInterface::class);
    }

    public function testMake()
    {
        $container = new Container();
        Container::setInstance($container);

        $psrResponse = new \Hyperf\HttpMessage\Base\Response();
        Context::set(ResponseInterface::class, $psrResponse);

        $response = new Response();

        // Test with string content
        $result = $response->make('Hello World', 200, ['X-Test' => 'Test']);
        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertEquals(200, $result->getStatusCode());
        $this->assertEquals('Hello World', (string) $result->getBody());
        $this->assertEquals('Test', $result->getHeaderLine('X-Test'));
        $this->assertEquals('text/plain', $result->getHeaderLine('content-type'));

        // Test with array content
        $result = $response->make(['key' => 'value'], 201);
        $this->assertEquals(201, $result->getStatusCode());
        $this->assertEquals('{"key":"value"}', (string) $result->getBody());
        $this->assertEquals('application/json', $result->getHeaderLine('content-type'));

        // Test with Arrayable content
        $arrayable = new class implements Arrayable {
            public function toArray(): array
            {
                return ['foo' => 'bar'];
            }
        };
        $result = $response->make($arrayable);
        $this->assertEquals('{"foo":"bar"}', (string) $result->getBody());
        $this->assertEquals('application/json', $result->getHeaderLine('content-type'));

        // Test with Jsonable content
        $jsonable = new class implements Jsonable {
            public function toJson(int $options = 0): string
            {
                return '{"baz":"qux"}';
            }
        };
        $result = $response->make($jsonable);
        $this->assertEquals('{"baz":"qux"}', (string) $result->getBody());
        $this->assertEquals('application/json', $result->getHeaderLine('content-type'));
    }

    public function testNoContent()
    {
        $container = new Container();
        Container::setInstance($container);

        $psrResponse = new \Hyperf\HttpMessage\Base\Response();
        Context::set(ResponseInterface::class, $psrResponse);

        $response = new Response();
        $result = $response->noContent(204, ['X-Empty' => 'Yes']);

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertEquals(204, $result->getStatusCode());
        $this->assertEquals('', (string) $result->getBody());
        $this->assertEquals('Yes', $result->getHeaderLine('X-Empty'));
    }

    public function testView()
    {
        $psrResponse = new \Hyperf\HttpMessage\Base\Response();
        Context::set(ResponseInterface::class, $psrResponse);

        $container = new Container();
        Container::setInstance($container);

        $renderer = m::mock(RenderInterface::class);
        $renderer->shouldReceive('render')->with('test-view', ['data' => 'value'])->andReturn(
            (new HttpServerResponse())->withAddedHeader('content-type', 'text/html')->withBody(new SwooleStream('<h1>Test</h1>'))
        );

        $container->instance(RenderInterface::class, $renderer);

        $response = new Response();
        $result = $response->view('test-view', ['data' => 'value'], 200, ['X-View' => 'Rendered']);

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertEquals(200, $result->getStatusCode());
        $this->assertEquals('<h1>Test</h1>', (string) $result->getBody());
        $this->assertEquals('Rendered', $result->getHeaderLine('X-View'));
        $this->assertEquals('text/html', $result->getHeaderLine('content-type'));
    }

    public function testGetPsr7Response()
    {
        $psrResponse = new \Hyperf\HttpMessage\Base\Response();
        $response = new Response($psrResponse);

        $this->assertSame($psrResponse, $response->getPsr7Response());
    }

    public function testFileWithFileNotFoundException()
    {
        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('isFile')
            ->with('file_path')
            ->once()
            ->andReturn(false);

        $container = new Container();
        $container->instance(Filesystem::class, $filesystem);
        Container::setInstance($container);

        $this->expectException(FileNotFoundException::class);

        $psrResponse = new \Hyperf\HttpMessage\Base\Response();
        (new Response($psrResponse))
            ->file('file_path');
    }

    public function testFile()
    {
        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('isFile')
            ->with($filePath = 'file_path')
            ->once()
            ->andReturn(true);
        $filesystem->shouldReceive('get')
            ->with($filePath)
            ->once()
            ->andReturn($fileContent = 'file_content');

        $container = new Container();
        $container->instance(Filesystem::class, $filesystem);
        Container::setInstance($container);

        $psrResponse = new \Hyperf\HttpMessage\Base\Response();
        $response = (new Response($psrResponse))
            ->file('file_path', ['Content-Type' => $mime = 'image/jpeg']);

        $this->assertSame($mime, $response->getHeader('Content-Type')[0]);
        $this->assertSame($fileContent, (string) $response->getBody());
    }

    public function testStream()
    {
        $psrResponse = m::mock(\Hyperf\HttpMessage\Server\Response::class)->makePartial();
        $psrResponse->shouldReceive('write')
            ->with($content = 'Streaming content')
            ->once()
            ->andReturnTrue();
        Context::set(ResponseInterface::class, $psrResponse);

        $response = new \Hypervel\Http\Response();
        $stream = new SwooleStream($content);
        $result = $response->stream(
            fn () => $stream->eof() ? false : $stream->read(1024),
            ['X-Download' => 'Yes']
        );

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertEquals([
            'Content-Type' => ['text/event-stream'],
            'X-Download' => ['Yes'],
        ], $result->getHeaders());
    }

    public function testStreamWithStringResult()
    {
        $psrResponse = m::mock(\Hyperf\HttpMessage\Server\Response::class)->makePartial();
        $psrResponse->shouldReceive('write')
            ->with($content = 'Streaming content')
            ->once()
            ->andReturnTrue();
        Context::set(ResponseInterface::class, $psrResponse);

        $response = new \Hypervel\Http\Response();
        $result = $response->stream(
            fn () => $content,
            ['X-Download' => 'Yes']
        );

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertEquals([
            'Content-Type' => ['text/event-stream'],
            'X-Download' => ['Yes'],
        ], $result->getHeaders());
    }

    public function testStreamWithNonChunkable()
    {
        $psrResponse = m::mock(ResponsePlusInterface::class);
        Context::set(ResponseInterface::class, $psrResponse);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The response is not a chunkable response.');

        (new \Hypervel\Http\Response())
            ->stream(fn () => 'test');
    }

    public function testStreamDownload()
    {
        $psrResponse = m::mock(\Hyperf\HttpMessage\Server\Response::class)->makePartial();
        $psrResponse->shouldReceive('write')
            ->with($content = 'File content')
            ->once()
            ->andReturnTrue();
        Context::set(ResponseInterface::class, $psrResponse);

        $response = new \Hypervel\Http\Response();
        $stream = new SwooleStream($content);
        $result = $response->streamDownload(
            fn () => $stream->eof() ? false : $stream->read(1024),
            'test.txt',
            ['X-Download' => 'Yes']
        );

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertEquals([
            'Content-Type' => ['application/octet-stream'],
            'Content-Description' => ['File Transfer'],
            'Pragma' => ['no-cache'],
            'Content-Disposition' => ['attachment; filename=test.txt'],
            'X-Download' => ['Yes'],
        ], $result->getHeaders());
    }

    public function testStreamDownloadWithRangeHeader()
    {
        $psrResponse = m::mock(\Hyperf\HttpMessage\Server\Response::class)->makePartial();
        $psrResponse->shouldReceive('write')
            ->with($content = 'File content')
            ->once()
            ->andReturnTrue();
        Context::set(ResponseInterface::class, $psrResponse);

        $this->mockRequest([
            'Range' => ['bytes=0-1023'],
        ]);

        $response = new \Hypervel\Http\Response();
        $stream = new SwooleStream($content);
        $result = $response->withRangeHeaders(8888)
            ->streamDownload(
                fn () => $stream->eof() ? false : $stream->read(1024),
                'test.txt',
                ['X-Download' => 'Yes'],
                'attachment',
            );

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertSame($result->getStatusCode(), 206);
        $this->assertEquals([
            'Content-Type' => ['application/octet-stream'],
            'Content-Description' => ['File Transfer'],
            'Pragma' => ['no-cache'],
            'Content-Disposition' => ['attachment; filename=test.txt'],
            'X-Download' => ['Yes'],
            'Accept-Ranges' => ['bytes'],
            'Content-Range' => ['bytes 0-1023/8888'],
        ], $result->getHeaders());
    }

    public function testStreamDownloadWithRangeHeaderAndWithoutContentLength()
    {
        $psrResponse = m::mock(\Hyperf\HttpMessage\Server\Response::class)->makePartial();
        $psrResponse->shouldReceive('write')
            ->with($content = 'File content')
            ->once()
            ->andReturnTrue();
        Context::set(ResponseInterface::class, $psrResponse);

        $this->mockRequest([
            'Range' => ['bytes=1024-2047'],
        ]);

        $response = new \Hypervel\Http\Response();
        $stream = new SwooleStream($content);
        $result = $response->withRangeHeaders()
            ->streamDownload(
                fn () => $stream->eof() ? false : $stream->read(1024),
                'test.txt',
                ['X-Download' => 'Yes']
            );

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertSame($result->getStatusCode(), 206);
        $this->assertEquals([
            'Content-Type' => ['application/octet-stream'],
            'Content-Description' => ['File Transfer'],
            'Pragma' => ['no-cache'],
            'Content-Disposition' => ['attachment; filename=test.txt'],
            'X-Download' => ['Yes'],
            'Accept-Ranges' => ['bytes'],
            'Content-Range' => ['bytes 1024-2047/*'],
        ], $result->getHeaders());
    }

    public function testStreamDownloadWithInvalidRangeHeader()
    {
        $psrResponse = m::mock(\Hyperf\HttpMessage\Server\Response::class)->makePartial();
        $psrResponse->shouldNotReceive('write');
        Context::set(ResponseInterface::class, $psrResponse);

        $this->mockRequest([
            'Range' => ['bytes=9000-10000'],
        ]);

        $this->expectException(RangeNotSatisfiableHttpException::class);

        $response = new \Hypervel\Http\Response();
        $stream = new SwooleStream('File content');
        $response->withRangeHeaders(8888)
            ->streamDownload(
                fn () => $stream->eof() ? false : $stream->read(1024),
                'test.txt',
                ['X-Download' => 'Yes'],
                'attachment',
            );
    }

    protected function mockRequest(array $headers = [], string $method = 'GET'): ServerRequestPlusInterface
    {
        $request = m::mock(ServerRequestPlusInterface::class);
        $request->shouldReceive('getMethod')->andReturn($method);

        foreach ($headers as $key => $value) {
            $request->shouldReceive('getHeader')->with($key)->andReturn($value);
            $request->shouldReceive('hasHeader')->with($key)->andReturn(true);
        }

        $request->shouldReceive('hasHeader')->andReturn(false);

        Context::set(ServerRequestInterface::class, $request);

        return $request;
    }
}
