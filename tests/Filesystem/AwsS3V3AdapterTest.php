<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Filesystem\AwsS3V3Adapter;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToReadFile;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class AwsS3V3AdapterTest extends TestCase
{
    #[DataProvider('ranges')]
    public function testReadStreamRangeUsesANativePrefixedGetObjectRange(
        ?int $start,
        ?int $end,
        string $range,
    ): void {
        $captured = null;
        $handler = new MockHandler([
            function (CommandInterface $command) use (&$captured): Result {
                $captured = $command;

                return new Result(['Body' => Utils::streamFor('range-body')]);
            },
        ]);
        $adapter = $this->adapter($handler, [
            'bucket' => 'bucket',
            'root' => 'tenant',
            'stream_reads' => true,
        ]);

        $stream = $adapter->readStreamRange('file.txt', $start, $end);

        $this->assertIsResource($stream);
        $this->assertSame('range-body', stream_get_contents($stream));
        fclose($stream);
        $this->assertInstanceOf(CommandInterface::class, $captured);
        $this->assertSame('GetObject', $captured->getName());
        $this->assertSame('bucket', $captured['Bucket']);
        $this->assertSame('tenant/file.txt', $captured['Key']);
        $this->assertSame($range, $captured['Range']);
        $this->assertTrue($captured['@http']['stream']);
    }

    public static function ranges(): array
    {
        return [
            [3, 5, 'bytes=3-5'],
            [3, null, 'bytes=3-'],
            [null, 3, 'bytes=-3'],
        ];
    }

    public function testReadStreamRangeWithoutBoundsDelegatesToTheFullStream(): void
    {
        $handler = new MockHandler([
            new Result(['Body' => Utils::streamFor('full-body')]),
        ]);
        $adapter = $this->adapter($handler, ['bucket' => 'bucket']);

        $stream = $adapter->readStreamRange('file.txt', null, null);

        $this->assertIsResource($stream);
        $this->assertSame('full-body', stream_get_contents($stream));
        fclose($stream);
    }

    public function testReadStreamRangeRejectsInvalidArgumentsBeforeClientIo(): void
    {
        $adapter = $this->adapter(new MockHandler([]), ['bucket' => 'bucket']);

        foreach ([[-1, 2], [1, -2], [3, 2], [null, 0]] as [$start, $end]) {
            try {
                $adapter->readStreamRange('file.txt', $start, $end);
                $this->fail('Expected the invalid stream range to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('A stream range must be', $exception->getMessage());
            }
        }
    }

    public function testReadStreamRangePreservesAdapterOptionsButOwnsTheRange(): void
    {
        $captured = null;
        $handler = new MockHandler([
            function (CommandInterface $command) use (&$captured): Result {
                $captured = $command;

                return new Result(['Body' => Utils::streamFor('body')]);
            },
        ]);
        $adapter = $this->adapter($handler, [
            'bucket' => 'bucket',
            'stream_reads' => true,
            'options' => [
                'RequestPayer' => 'requester',
                'Range' => 'bytes=0-999',
                '@http' => ['stream' => false, 'timeout' => 12],
            ],
        ]);

        $stream = $adapter->readStreamRange('file.txt', 1, 2);
        fclose($stream);

        $this->assertInstanceOf(CommandInterface::class, $captured);
        $this->assertSame('bytes=1-2', $captured['Range']);
        $this->assertSame('requester', $captured['RequestPayer']);
        $this->assertFalse($captured['@http']['stream']);
        $this->assertSame(12, $captured['@http']['timeout']);
    }

    public function testStreamReadsPreserveConfiguredHttpSiblingsForPlainAndRangeReads(): void
    {
        $commands = [];
        $handler = new MockHandler([
            function (CommandInterface $command) use (&$commands): Result {
                $commands[] = $command;

                return new Result(['Body' => Utils::streamFor('plain')]);
            },
            function (CommandInterface $command) use (&$commands): Result {
                $commands[] = $command;

                return new Result(['Body' => Utils::streamFor('range')]);
            },
        ]);
        $adapter = $this->adapter($handler, [
            'bucket' => 'bucket',
            'root' => 'tenant',
            'stream_reads' => true,
            'options' => [
                'Bucket' => 'wrong-bucket',
                'Key' => 'wrong-key',
                'Range' => 'bytes=0-999',
                '@http' => ['timeout' => 12],
            ],
        ]);

        $plain = $adapter->readStream('plain.txt');
        $range = $adapter->readStreamRange('range.txt', 2, 4);

        $this->assertIsResource($plain);
        $this->assertIsResource($range);
        fclose($plain);
        fclose($range);
        $this->assertCount(2, $commands);

        foreach ($commands as $command) {
            $this->assertSame('bucket', $command['Bucket']);
            $this->assertTrue($command['@http']['stream']);
            $this->assertSame(12, $command['@http']['timeout']);
        }

        $this->assertSame('tenant/plain.txt', $commands[0]['Key']);
        $this->assertSame('tenant/range.txt', $commands[1]['Key']);
        $this->assertSame('bytes=2-4', $commands[1]['Range']);
    }

    public function testReadStreamRangeWrapsClientFailures(): void
    {
        $handler = new MockHandler([new RuntimeException('S3 failed')]);
        $adapter = $this->adapter($handler, ['bucket' => 'bucket', 'throw' => true]);

        $this->expectException(UnableToReadFile::class);
        $this->expectExceptionMessage('S3 failed');

        $adapter->readStreamRange('file.txt', 1, 2);
    }

    public function testReadStreamRangeRejectsAResultWithoutAResource(): void
    {
        $body = m::mock(StreamInterface::class);
        $body->shouldReceive('detach')->once()->andReturnNull();
        $handler = new MockHandler([new Result(['Body' => $body])]);
        $adapter = $this->adapter($handler, ['bucket' => 'bucket', 'throw' => true]);

        $this->expectException(UnableToReadFile::class);
        $this->expectExceptionMessage('Downloaded object does not contain a file resource.');

        $adapter->readStreamRange('file.txt', 1, 2);
    }

    public function testReadFailuresReturnNullWhenExceptionsAreDisabled(): void
    {
        $handler = new MockHandler([
            new RuntimeException('plain failed'),
            new RuntimeException('range failed'),
        ]);
        $adapter = $this->adapter($handler, ['bucket' => 'bucket']);

        $this->assertNull($adapter->readStream('file.txt'));
        $this->assertNull($adapter->readStreamRange('file.txt', 1, 2));
    }

    private function adapter(MockHandler $handler, array $config): AwsS3V3Adapter
    {
        $client = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => false,
            'handler' => $handler,
        ]);

        return new AwsS3V3Adapter(
            m::mock(FilesystemOperator::class),
            m::mock(FlysystemAdapter::class),
            $config,
            $client,
        );
    }
}
