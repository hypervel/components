<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Client;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Http\Client\LineReader;
use Hypervel\Tests\TestCase;
use Psr\Http\Message\StreamInterface;

class LineReaderTest extends TestCase
{
    public function testBufferedLinesRemainAvailableAfterTheBodyReachesEof(): void
    {
        $stream = new class(Utils::streamFor("first\r\nsecond\nlast")) implements StreamInterface {
            use StreamDecoratorTrait;

            private StreamInterface $stream;

            public int $reads = 0;

            /**
             * Count underlying reads.
             */
            public function read(int $length): string
            {
                ++$this->reads;

                return $this->stream->read($length);
            }
        };
        $reader = new LineReader;

        $this->assertSame("first\r\n", $reader->readLine($stream));
        $this->assertTrue($stream->eof());
        $this->assertSame("second\n", $reader->readLine($stream));
        $this->assertSame('last', $reader->readLine($stream));
        $this->assertSame('', $reader->readLine($stream));
        $this->assertSame(1, $stream->reads);
    }
}
