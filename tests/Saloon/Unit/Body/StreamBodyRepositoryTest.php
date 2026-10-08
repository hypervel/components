<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit\Body;

use GuzzleHttp\Psr7\Utils;
use Hypervel\Saloon\Repositories\Body\StreamBodyRepository;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class StreamBodyRepositoryTest extends TestCase
{
    public function testTheStoreIsEmptyByDefault(): void
    {
        $body = new StreamBodyRepository;

        $this->assertNull($body->all());
        $this->assertNull($body->get());
    }

    public function testTheStoreCanHaveADefaultStreamProvided(): void
    {
        $resource = tmpfile();

        try {
            $body = new StreamBodyRepository($resource);

            $this->assertSame($resource, $body->all());
            $this->assertSame($resource, $body->get());
        } finally {
            fclose($resource);
        }
    }

    public function testYouCanSetIt(): void
    {
        $resourceA = fopen('php://memory', 'rw+');
        $resourceB = fopen('php://memory', 'rw+');

        try {
            fwrite($resourceA, 'Howdy');
            fwrite($resourceB, 'Yeehaw');
            rewind($resourceB);

            $body = new StreamBodyRepository($resourceA);

            $body->set($resourceB);

            $this->assertSame($resourceB, $body->get());
            $this->assertSame('Yeehaw', (string) $body->toStream());
        } finally {
            // The repository closes resource A when it is replaced.
            foreach ([$resourceA, $resourceB] as $resource) {
                if (is_resource($resource)) {
                    fclose($resource);
                }
            }
        }
    }

    public function testSettingTheSameResourceKeepsItOpen(): void
    {
        $resource = fopen('php://memory', 'rw+');

        try {
            fwrite($resource, 'Howdy');

            $body = new StreamBodyRepository($resource);
            $body->toStream();
            $body->set($body->get());

            $this->assertSame('Howdy', (string) $body->toStream());
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    public function testACopyKeepsTheResourceOpenWhenTheOriginalIsReleased(): void
    {
        $resource = fopen('php://memory', 'rw+');

        try {
            fwrite($resource, 'Howdy');

            $body = new StreamBodyRepository($resource);
            $copy = clone $body;

            $body->toStream();
            unset($body);

            $this->assertSame('Howdy', (string) $copy->toStream());
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    public function testYouCanSetAnInstanceOfStreamInterface(): void
    {
        $streamA = Utils::streamFor('Howdy!');
        $streamB = Utils::streamFor('Partner!');

        $body = new StreamBodyRepository($streamA);
        $body->set($streamB);

        $this->assertSame($streamB, $body->get());
        $this->assertSame($streamB, $body->toStream());
    }

    public function testYouCanConditionallySetOnTheStore(): void
    {
        $body = new StreamBodyRepository;

        $resourceA = fopen('php://memory', 'rw+');
        $resourceB = fopen('php://memory', 'rw+');

        try {
            fwrite($resourceA, 'Howdy');
            fwrite($resourceB, 'Yeehaw');

            $body->when(true, fn (StreamBodyRepository $body): StreamBodyRepository => $body->set($resourceA));
            $body->when(false, fn (StreamBodyRepository $body): StreamBodyRepository => $body->set($resourceB));

            $this->assertSame($resourceA, $body->get());
        } finally {
            fclose($resourceA);
            fclose($resourceB);
        }
    }

    public function testYouCanCheckIfTheStoreIsEmptyOrNot(): void
    {
        $body = new StreamBodyRepository;

        $this->assertTrue($body->isEmpty());
        $this->assertFalse($body->isNotEmpty());

        $resource = tmpfile();

        try {
            $body->set($resource);

            $this->assertFalse($body->isEmpty());
            $this->assertTrue($body->isNotEmpty());
        } finally {
            fclose($resource);
        }
    }

    #[DataProvider('invalidValues')]
    public function testItWillThrowAnExceptionIfTheValueIsNotAResourceOrStreamInterfaceWhenInstantiating(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StreamBodyRepository($value);
    }

    #[DataProvider('invalidValues')]
    public function testItWillThrowAnExceptionIfTheValueIsNotAResourceOrStreamInterfaceWhenSetting(mixed $value): void
    {
        $body = new StreamBodyRepository;

        $this->expectException(InvalidArgumentException::class);

        $body->set($value);
    }

    /**
     * Get the values a stream body rejects.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'string' => ['Howdy'];
        yield 'integer' => [123];
        yield 'array' => [[]];
        yield 'boolean' => [false];
    }

    public function testItAllowsNullValues(): void
    {
        $body = new StreamBodyRepository(null);

        $this->assertNull($body->get());
        $this->assertTrue($body->isEmpty());

        $body->set(Utils::streamFor('Howdy!'));
        $body->set(null);

        $this->assertNull($body->get());
        $this->assertTrue($body->isEmpty());
    }

    public function testItCreatesAnEmptySeekableStreamForNull(): void
    {
        $stream = (new StreamBodyRepository)->toStream();

        $this->assertTrue($stream->isSeekable());
        $this->assertSame('', (string) $stream);
    }
}
