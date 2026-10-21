<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Filesystem\AwsS3V3Adapter;
use Hypervel\Filesystem\ClientPooledFilesystem;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Filesystem\FilesystemManager;
use Hypervel\Filesystem\FilesystemPoolProxy;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToReadFile;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

use function Hypervel\Coroutine\parallel;

class AwsS3V3AdapterTest extends TestCase
{
    public function testConfiguredAndNativeUrlsEncodeRawPathsIdentically(): void
    {
        $path = 'nested/report%2F v?x#y+z.txt';
        $expected = 'https://bucket.s3.amazonaws.com/tenant/nested/report%252F%20v%3Fx%23y%2Bz.txt';
        $config = [
            'bucket' => 'bucket',
            'root' => 'tenant',
        ];

        $this->assertSame($expected, $this->adapter(new MockHandler([]), $config)->url($path));
        $this->assertSame($expected, $this->adapter(new MockHandler([]), [
            ...$config,
            'url' => 'https://bucket.s3.amazonaws.com',
        ])->url($path));
    }

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

    public function testReadStreamDefaultsToLazyCoroutineSafeStreaming(): void
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        [$reader, $writer] = $sockets;
        $this->assertTrue(stream_set_timeout($reader, 1));
        $captured = null;
        $handler = new MockHandler([
            function (CommandInterface $command) use (&$captured, $reader): Result {
                $captured = $command;

                return new Result(['Body' => Utils::streamFor($reader)]);
            },
        ]);
        $adapter = $this->adapter($handler, ['bucket' => 'bucket']);
        $stream = null;

        try {
            $stream = $adapter->readStream('file.txt');

            $this->assertIsResource($stream);
            $this->assertInstanceOf(CommandInterface::class, $captured);
            $this->assertTrue($captured['@http']['stream']);

            $results = parallel([
                'read' => static fn (): string|false => fread($stream, 8),
                'write' => static fn (): int|false => fwrite($writer, 'streamed'),
            ]);

            $this->assertSame('streamed', $results['read']);
            $this->assertSame(8, $results['write']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            } elseif (is_resource($reader)) {
                fclose($reader);
            }

            if (is_resource($writer)) {
                fclose($writer);
            }
        }
    }

    public function testStreamReadsCanBeDisabled(): void
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
            'stream_reads' => false,
        ]);

        $stream = $adapter->readStream('file.txt');

        $this->assertIsResource($stream);
        fclose($stream);
        $this->assertInstanceOf(CommandInterface::class, $captured);
        $this->assertArrayNotHasKey('stream', $captured['@http'] ?? []);
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

    #[TestWith([false])]
    #[TestWith([true])]
    public function testStreamReadsPreserveConfiguredHttpSiblingsForPlainAndRangeReads(bool $wholeDriver): void
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
            function (CommandInterface $command) use (&$commands): Result {
                $commands[] = $command;

                return new Result(['Body' => Utils::streamFor('pooled')]);
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
        $pools = new PoolManager;
        $definition = new PoolDefinition('filesystem:http-options', 's3', 'test', PoolOptions::fromArray([]));
        $pooled = $wholeDriver ? new FilesystemPoolProxy(
            $definition,
            static fn (): AwsS3V3Adapter => $adapter,
            $pools,
            $adapter->getConfig(),
        ) : new ClientPooledFilesystem(
            $definition,
            $adapter->getClient(...),
            static fn (object $client): AwsS3V3Adapter => $adapter,
            $pools,
            $adapter->getConfig(),
        );

        try {
            $stream = $pooled->getOperator()->readStream('pooled.txt');

            try {
                $this->assertSame('pooled', stream_get_contents($stream));
            } finally {
                fclose($stream);
            }
        } finally {
            $pools->purgeAll();
        }

        $this->assertCount(3, $commands);

        foreach ($commands as $command) {
            $this->assertSame('bucket', $command['Bucket']);
            $this->assertTrue($command['@http']['stream']);
            $this->assertSame(12, $command['@http']['timeout']);
        }

        $this->assertSame('tenant/plain.txt', $commands[0]['Key']);
        $this->assertSame('tenant/range.txt', $commands[1]['Key']);
        $this->assertSame('bytes=2-4', $commands[1]['Range']);
        $this->assertSame('tenant/pooled.txt', $commands[2]['Key']);
    }

    #[DataProvider('readThroughCloudSides')]
    public function testReadThroughRangesPreserveNativeRequestsAndTheOuterFailurePolicy(bool $primary, ?string $poolType): void
    {
        $command = null;
        $failure = new RuntimeException('S3 failed');
        $handler = new MockHandler([
            function (CommandInterface $request) use (&$command): Result {
                $command = $request;

                [$reader, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                fwrite($writer, 'range');
                fclose($writer);

                return new Result(['Body' => Utils::streamFor($reader)]);
            },
            $failure,
        ]);
        $adapter = $this->adapter($handler, [
            'bucket' => 'bucket',
            'root' => 'tenant',
            'throw' => false,
            'report' => true,
            'options' => ['@http' => ['timeout' => 12]],
        ]);
        $adapter->getDriver()->shouldReceive('fileExists')->andReturn(true);
        $emptyDriver = m::mock(FilesystemOperator::class);
        $emptyDriver->shouldReceive('fileExists')->andReturn(false);
        $empty = new FilesystemAdapter($emptyDriver, m::mock(FlysystemAdapter::class));
        $pools = new PoolManager;
        $definition = new PoolDefinition('filesystem:read-through-range', 's3', 'test', PoolOptions::fromArray([]));
        $cloud = match ($poolType) {
            'client' => new ClientPooledFilesystem(
                $definition,
                $adapter->getClient(...),
                static fn (object $client): AwsS3V3Adapter => $adapter,
                $pools,
                $adapter->getConfig(),
            ),
            'driver' => new FilesystemPoolProxy(
                $definition,
                static fn (): AwsS3V3Adapter => $adapter,
                $pools,
                $adapter->getConfig(),
            ),
            null => $adapter,
        };
        $exceptionHandler = m::mock(ExceptionHandler::class);
        $exceptionHandler->shouldNotReceive('report');
        Container::getInstance()->instance(ExceptionHandler::class, $exceptionHandler);
        $manager = new FilesystemManager(Container::getInstance());
        $manager->set('primary', $primary ? $cloud : $empty);
        $manager->set('fallback', $primary ? $empty : $cloud);
        $readThrough = $manager->build([
            'driver' => 'read-through',
            'primary' => 'primary',
            'fallback' => 'fallback',
            'copy' => $primary,
            'throw' => true,
            'report' => false,
            'prefix' => 'outer',
        ]);

        try {
            $stream = $readThrough->readStreamRange('file.txt', 2, 4);

            try {
                $this->assertSame('range', stream_get_contents($stream));

                if ($poolType !== null) {
                    $this->assertSame(1, $pools->get($definition->identity)->getBorrowedCount());
                }
            } finally {
                fclose($stream);
            }

            $this->assertSame('bytes=2-4', $command['Range']);
            $this->assertSame('tenant/outer/file.txt', $command['Key']);
            $this->assertSame(12, $command['@http']['timeout']);

            try {
                $readThrough->readStreamRange('file.txt', 2, 4);
                $this->fail('Expected the composite failure policy to throw.');
            } catch (UnableToReadFile $exception) {
                $this->assertSame($failure, $exception->getPrevious());
            }

            if ($poolType !== null) {
                $this->assertSame(0, $pools->get($definition->identity)->getBorrowedCount());
            }
        } finally {
            $pools->purgeAll();
        }
    }

    /**
     * Provide read-through sides that can serve native ranges.
     */
    public static function readThroughCloudSides(): array
    {
        return [
            'client-pooled primary' => [true, 'client'],
            'client-pooled fallback without promotion' => [false, 'client'],
            'driver-pooled primary' => [true, 'driver'],
            'driver-pooled fallback without promotion' => [false, 'driver'],
            'non-pooled primary' => [true, null],
        ];
    }

    public function testReadStreamRangeWrapsClientFailures(): void
    {
        $handler = new MockHandler([new RuntimeException('S3 failed')]);
        $adapter = $this->adapter($handler, ['bucket' => 'bucket', 'throw' => true]);

        $this->expectException(UnableToReadFile::class);
        $this->expectExceptionMessageIsOrContains('S3 failed');

        $adapter->readStreamRange('file.txt', 1, 2);
    }

    public function testReadStreamRangePreservesCancellationWithoutReporting(): void
    {
        $cancellation = new CanceledException('read canceled');
        $exceptionHandler = m::mock(ExceptionHandler::class);
        $exceptionHandler->shouldNotReceive('report');
        Container::getInstance()->bind(ExceptionHandler::class, static fn () => $exceptionHandler);
        $handler = new MockHandler([$cancellation]);
        $adapter = $this->adapter($handler, [
            'bucket' => 'bucket',
            'report' => true,
            'throw' => false,
        ]);

        try {
            $adapter->readStreamRange('file.txt', 1, 2);
            $this->fail('Expected the cancellation to escape.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testReadStreamRangeRejectsAResultWithoutAResource(): void
    {
        $body = m::mock(StreamInterface::class);
        $body->shouldReceive('detach')->once()->andReturnNull();
        $handler = new MockHandler([new Result(['Body' => $body])]);
        $adapter = $this->adapter($handler, ['bucket' => 'bucket', 'throw' => true]);

        $this->expectException(UnableToReadFile::class);
        $this->expectExceptionMessageIsOrContains('Downloaded object does not contain a file resource.');

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
