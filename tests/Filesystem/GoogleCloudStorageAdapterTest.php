<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Filesystem\GoogleCloudStorageAdapter;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter as FlysystemGoogleCloudAdapter;
use League\Flysystem\UnableToReadFile;
use Mockery as m;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class GoogleCloudStorageAdapterTest extends TestCase
{
    public function testUrlUsesTheConfiguredApiUriOrBucketEndpoint(): void
    {
        $client = m::mock(StorageClient::class);

        $this->assertSame(
            'https://storage.googleapis.com/bucket/tenant/file.txt',
            $this->adapter($client, ['bucket' => 'bucket', 'root' => 'tenant'])->url('file.txt'),
        );
        $this->assertSame(
            'https://cdn.example.com/storage/tenant/file.txt',
            $this->adapter($client, [
                'bucket' => 'bucket',
                'root' => 'tenant',
                'storageApiUri' => 'https://cdn.example.com/storage',
            ])->url('file.txt'),
        );
    }

    public function testReadStreamRangeSendsANativeRangeHeaderForThePrefixedObject(): void
    {
        $stream = Utils::streamFor('range-body');
        $object = m::mock(StorageObject::class);
        $object->shouldReceive('downloadAsStream')
            ->once()
            ->with([
                'restOptions' => [
                    'headers' => ['Range' => 'bytes=3-5'],
                    'stream' => true,
                ],
            ])
            ->andReturn($stream);
        $bucket = m::mock(Bucket::class);
        $bucket->shouldReceive('object')->once()->with('tenant/file.txt')->andReturn($object);
        $client = m::mock(StorageClient::class);
        $client->shouldReceive('bucket')->once()->with('bucket')->andReturn($bucket);
        $adapter = $this->adapter($client, [
            'bucket' => 'bucket',
            'root' => 'tenant',
            'stream_reads' => true,
        ]);

        $resource = $adapter->readStreamRange('file.txt', 3, 5);

        $this->assertIsResource($resource);
        $this->assertSame('range-body', stream_get_contents($resource));
        fclose($resource);
    }

    public function testReadStreamRangeSupportsOpenAndSuffixRangesWithoutForcingStreaming(): void
    {
        $object = m::mock(StorageObject::class);
        $object->shouldReceive('downloadAsStream')
            ->once()
            ->with(['restOptions' => ['headers' => ['Range' => 'bytes=3-']]])
            ->andReturn(Utils::streamFor('open'));
        $object->shouldReceive('downloadAsStream')
            ->once()
            ->with(['restOptions' => ['headers' => ['Range' => 'bytes=-3']]])
            ->andReturn(Utils::streamFor('suffix'));
        $bucket = m::mock(Bucket::class);
        $bucket->shouldReceive('object')->twice()->with('file.txt')->andReturn($object);
        $client = m::mock(StorageClient::class);
        $client->shouldReceive('bucket')->twice()->with('bucket')->andReturn($bucket);
        $adapter = $this->adapter($client, [
            'bucket' => 'bucket',
            'stream_reads' => false,
        ]);

        $open = $adapter->readStreamRange('file.txt', 3, null);
        $suffix = $adapter->readStreamRange('file.txt', null, 3);

        $this->assertSame('open', stream_get_contents($open));
        $this->assertSame('suffix', stream_get_contents($suffix));
        fclose($open);
        fclose($suffix);
    }

    public function testReadStreamRangeWithoutBoundsDelegatesToTheFullStream(): void
    {
        $body = Utils::streamFor('full-body');
        $object = m::mock(StorageObject::class);
        $object->shouldReceive('downloadAsStream')
            ->once()
            ->with(['restOptions' => ['stream' => true]])
            ->andReturn($body);
        $bucket = m::mock(Bucket::class);
        $bucket->shouldReceive('object')->once()->with('file.txt')->andReturn($object);
        $client = m::mock(StorageClient::class);
        $client->shouldReceive('bucket')->once()->with('bucket')->andReturn($bucket);
        $adapter = $this->adapter($client, ['bucket' => 'bucket']);

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
        $object = m::mock(StorageObject::class);
        $object->shouldReceive('downloadAsStream')
            ->once()
            ->with(['restOptions' => ['stream' => true]])
            ->andReturn(Utils::streamFor($reader));
        $bucket = m::mock(Bucket::class);
        $bucket->shouldReceive('object')->once()->with('file.txt')->andReturn($object);
        $client = m::mock(StorageClient::class);
        $client->shouldReceive('bucket')->once()->with('bucket')->andReturn($bucket);
        $adapter = $this->adapter($client, ['bucket' => 'bucket']);
        $stream = null;

        try {
            $stream = $adapter->readStream('file.txt');

            $this->assertIsResource($stream);

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

    public function testReadStreamRangeRejectsInvalidArgumentsBeforeClientIo(): void
    {
        $adapter = $this->adapter(m::mock(StorageClient::class), ['bucket' => 'bucket']);

        foreach ([[-1, 2], [1, -2], [3, 2], [null, 0]] as [$start, $end]) {
            try {
                $adapter->readStreamRange('file.txt', $start, $end);
                $this->fail('Expected the invalid stream range to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('A stream range must be', $exception->getMessage());
            }
        }
    }

    public function testReadStreamRangeWrapsClientFailures(): void
    {
        $object = m::mock(StorageObject::class);
        $object->shouldReceive('downloadAsStream')->once()->andThrow(new RuntimeException('GCS failed'));
        $bucket = m::mock(Bucket::class);
        $bucket->shouldReceive('object')->once()->with('file.txt')->andReturn($object);
        $client = m::mock(StorageClient::class);
        $client->shouldReceive('bucket')->once()->with('bucket')->andReturn($bucket);
        $adapter = $this->adapter($client, ['bucket' => 'bucket', 'throw' => true]);

        $this->expectException(UnableToReadFile::class);
        $this->expectExceptionMessage('GCS failed');

        $adapter->readStreamRange('file.txt', 3, 5);
    }

    public function testReadStreamRangeRejectsAResultWithoutAResourceWhenConfigured(): void
    {
        $stream = m::mock(StreamInterface::class);
        $stream->shouldReceive('detach')->once()->andReturnNull();
        $object = m::mock(StorageObject::class);
        $object->shouldReceive('downloadAsStream')->once()->andReturn($stream);
        $bucket = m::mock(Bucket::class);
        $bucket->shouldReceive('object')->once()->with('file.txt')->andReturn($object);
        $client = m::mock(StorageClient::class);
        $client->shouldReceive('bucket')->once()->with('bucket')->andReturn($bucket);
        $adapter = $this->adapter($client, ['bucket' => 'bucket', 'throw' => true]);

        $this->expectException(UnableToReadFile::class);
        $this->expectExceptionMessage('Downloaded object does not contain a file resource.');

        $adapter->readStreamRange('file.txt', 3, 5);
    }

    public function testReadFailuresReturnNullWhenExceptionsAreDisabled(): void
    {
        $object = m::mock(StorageObject::class);
        $object->shouldReceive('downloadAsStream')->twice()->andThrow(new RuntimeException('GCS failed'));
        $bucket = m::mock(Bucket::class);
        $bucket->shouldReceive('object')->once()->with('plain.txt')->andReturn($object);
        $bucket->shouldReceive('object')->once()->with('range.txt')->andReturn($object);
        $client = m::mock(StorageClient::class);
        $client->shouldReceive('bucket')->twice()->with('bucket')->andReturn($bucket);
        $adapter = $this->adapter($client, ['bucket' => 'bucket']);

        $this->assertNull($adapter->readStream('plain.txt'));
        $this->assertNull($adapter->readStreamRange('range.txt', 3, 5));
    }

    public function testNonResourceReturnsNullWhenExceptionsAreDisabled(): void
    {
        $stream = m::mock(StreamInterface::class);
        $stream->shouldReceive('detach')->once()->andReturnNull();
        $object = m::mock(StorageObject::class);
        $object->shouldReceive('downloadAsStream')->once()->andReturn($stream);
        $bucket = m::mock(Bucket::class);
        $bucket->shouldReceive('object')->once()->with('file.txt')->andReturn($object);
        $client = m::mock(StorageClient::class);
        $client->shouldReceive('bucket')->once()->with('bucket')->andReturn($bucket);
        $adapter = $this->adapter($client, ['bucket' => 'bucket']);

        $this->assertNull($adapter->readStream('file.txt'));
    }

    private function adapter(StorageClient $client, array $config): GoogleCloudStorageAdapter
    {
        return new GoogleCloudStorageAdapter(
            m::mock(FilesystemOperator::class),
            m::mock(FlysystemGoogleCloudAdapter::class),
            $config,
            $client,
        );
    }
}
