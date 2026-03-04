<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpMessage;

use Hypervel\HttpMessage\Server\Response;
use Hypervel\HttpMessage\Stream\SwooleFileStream;
use Hypervel\HttpMessage\Stream\SwooleStream;
use Hypervel\HttpServer\ResponseEmitter;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Stringable;
use Swoole\Http\Response as SwooleResponse;

/**
 * @internal
 * @coversNothing
 */
class SwooleStreamTest extends TestCase
{
    public function testSwooleFileStream()
    {
        $swooleResponse = m::mock(SwooleResponse::class);
        $file = __FILE__;
        $swooleResponse->shouldReceive('sendfile')->with($file)->once()->andReturn(true);
        $swooleResponse->shouldReceive('status')->with(200, '')->once()->andReturn(200);

        $response = new Response();
        $response = $response->withBody(new SwooleFileStream($file));

        $responseEmitter = new ResponseEmitter(null);
        $this->assertSame(null, $responseEmitter->emit($response, $swooleResponse, true));
    }

    public function testSwooleStream()
    {
        $swooleResponse = m::mock(SwooleResponse::class);
        $content = '{"id":1}';
        $swooleResponse->shouldReceive('end')->with($content)->once()->andReturn(true);
        $swooleResponse->shouldReceive('status')->with(200, '')->once()->andReturn(200);
        $swooleResponse->shouldReceive('header')->with('TOKEN', ['xxx'])->once()->andReturn(true);

        $response = new Response();
        $response = $response->withBody(new SwooleStream($content))->withHeader('TOKEN', 'xxx');

        $responseEmitter = new ResponseEmitter(null);
        $this->assertSame(null, $responseEmitter->emit($response, $swooleResponse, true));
    }

    public function testClose()
    {
        $random = microtime();

        $swooleStream = new SwooleStream($random);

        $this->assertSame($random, $swooleStream->getContents());

        $swooleStream->close();

        $this->assertSame('', $swooleStream->getContents());
    }

    public function testDetach()
    {
        $random = microtime();

        $swooleStream = new SwooleStream($random);

        $this->assertSame($random, $swooleStream->getContents());

        $swooleStream->close();

        $this->assertSame('', $swooleStream->getContents());
    }

    public function testTell()
    {
        $this->expectExceptionMessage('Cannot determine the position of a SwooleStream');
        $stream = new SwooleStream(microtime());
        $stream->tell();
    }

    public function testEof()
    {
        $random = microtime();

        $stream = new SwooleStream($random);

        $this->assertSame(false, $stream->eof());

        $stream->close();

        $this->assertSame(true, $stream->eof());
    }

    public function testSeek()
    {
        $this->expectExceptionMessage('Cannot seek a SwooleStream');
        $stream = new SwooleStream(microtime());
        $stream->seek(0);
    }

    public function testRewind()
    {
        $this->expectExceptionMessage('Cannot seek a SwooleStream');
        $stream = new SwooleStream(microtime());
        $stream->rewind();
    }

    public function testWriteAndWriteable()
    {
        $random = microtime();
        $stream = new SwooleStream($random);

        $this->assertSame(true, $stream->isWritable());

        $size = $stream->write($random);

        $this->assertSame(strlen($random), $size);

        $this->assertSame($random . $random, $stream->getContents());

        $stream->close();

        $this->assertSame(false, $stream->isWritable());

        $this->expectExceptionMessage('Cannot write to a non-writable stream');
        $stream->write($random);
    }

    public function testRead()
    {
        $random = microtime();
        $totalSize = strlen($random);

        $stream = new SwooleStream($random);

        $this->assertSame(true, $stream->isReadable());
        $this->assertSame($totalSize, $stream->getSize());

        $size = 1;
        $data = $stream->read($size);
        $this->assertSame(substr($random, 0, $size), $data);
        $this->assertSame($totalSize - $size, $stream->getSize());

        // read size >= data size
        $fullSize = strlen($random);
        $data = $stream->read($fullSize);
        $this->assertSame(substr($random, $size, $fullSize), $data);
        $this->assertSame(0, $stream->getSize());

        // read data from empty stream
        $data = $stream->read(1);
        $this->assertSame('', $data);
        $this->assertSame(0, $stream->getSize());

        $stream->close();

        $this->assertSame(true, $stream->isReadable());

        // read data from empty stream
        $data = $stream->read(1);
        $this->assertSame('', $data);
        $this->assertSame(0, $stream->getSize());
    }

    public function testGetContents()
    {
        $random = microtime();
        $stream = new SwooleStream($random);

        $this->assertSame($random, $stream->getContents());

        $this->assertSame($random, $stream->getContents());
    }

    public function testInstanceOfStringable()
    {
        $random = microtime();
        $stream = new SwooleStream($random);
        $this->assertInstanceOf(Stringable::class, $stream);
    }
}
