<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Repositories\Body;

use GuzzleHttp\Psr7\Utils;
use Hypervel\Saloon\Repositories\Body\StreamBodyRepository;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class StreamBodyRepositoryTest extends TestCase
{
    public function testItCreatesAnEmptySeekableStreamForNull(): void
    {
        $body = new StreamBodyRepository;

        $this->assertNull($body->all());
        $this->assertNull($body->get());
        $this->assertTrue($body->isEmpty());

        $stream = $body->toStream();

        $this->assertTrue($stream->isSeekable());
        $this->assertSame('', (string) $stream);
    }

    public function testItStoresAndReplacesResources(): void
    {
        $first = fopen('php://memory', 'rw+');
        $second = fopen('php://memory', 'rw+');
        $stream = null;

        try {
            fwrite($first, 'Howdy');
            fwrite($second, 'Yeehaw');
            rewind($second);

            $body = new StreamBodyRepository($first);
            $body->set($second);
            $stream = $body->toStream();

            $this->assertSame($second, $body->get());
            $this->assertSame('Yeehaw', (string) $stream);
            $this->assertFalse($body->isEmpty());
            $this->assertTrue($body->isNotEmpty());
        } finally {
            fclose($first);
            $stream?->close();
        }
    }

    public function testItReturnsTheOriginalPsrStream(): void
    {
        $stream = Utils::streamFor('Howdy!');
        $body = new StreamBodyRepository($stream);

        $this->assertSame($stream, $body->get());
        $this->assertSame($stream, $body->toStream());
    }

    public function testItMayBeConditionallyChanged(): void
    {
        $stream = Utils::streamFor('Howdy!');
        $body = new StreamBodyRepository;

        $body->when(true, fn (StreamBodyRepository $repository) => $repository->set($stream));
        $body->when(false, fn (StreamBodyRepository $repository) => $repository->set(null));

        $this->assertSame($stream, $body->get());
    }

    #[DataProvider('invalidValues')]
    public function testItRejectsValuesThatAreNotStreams(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StreamBodyRepository($value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'string' => ['Howdy'];
        yield 'integer' => [123];
        yield 'array' => [[]];
        yield 'boolean' => [false];
    }
}
