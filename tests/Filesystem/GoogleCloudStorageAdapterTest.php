<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use Google\Cloud\Core\AnonymousCredentials;
use Google\Cloud\Core\Exception\ServiceException;
use Google\Cloud\Core\Upload\AbstractUploader;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\InflateBodyStream;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Filesystem\GoogleCloudStorageAdapter;
use Hypervel\Filesystem\ReadThroughFilesystemAdapter;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter as FlysystemGoogleCloudAdapter;
use League\Flysystem\UnableToReadFile;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\parallel;

class GoogleCloudStorageAdapterTest extends TestCase
{
    public function testResumableUploadPreservesCancellation(): void
    {
        // @TODO: Remove this skip once google/cloud-core's ResumableUploader::upload preserves
        // the original exception as ServiceException's cause instead of discarding it.
        $this->markTestSkipped('google/cloud-core discards cancellation causes during resumable uploads.');

        $cancellation = new CanceledException('Upload canceled.');
        $requests = 0;
        $client = new StorageClient([
            'projectId' => 'test-project',
            'credentialsFetcher' => new AnonymousCredentials,
            'retries' => 0,
            'httpHandler' => function (RequestInterface $request) use ($cancellation, &$requests): Response {
                if (++$requests === 1) {
                    $this->assertSame('POST', $request->getMethod());
                    $this->assertStringContainsString('uploadType=resumable', $request->getUri()->getQuery());

                    return new Response(200, ['Location' => 'https://storage.example.test/resume']);
                }

                $this->assertSame('PUT', $request->getMethod());

                throw $cancellation;
            },
        ]);
        $flysystem = new FlysystemGoogleCloudAdapter($client->bucket('test-bucket'));
        $adapter = new GoogleCloudStorageAdapter(new Filesystem($flysystem), $flysystem, ['bucket' => 'test-bucket'], $client);
        $source = fopen('php://temp', 'w+b');
        ftruncate($source, AbstractUploader::RESUMABLE_LIMIT + 1);
        $caught = null;

        try {
            $adapter->put('upload.bin', $source, ['metadata' => ['contentType' => 'application/octet-stream']]);
        } catch (Throwable $exception) {
            $caught = $exception;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }

        $this->assertSame(2, $requests);
        $this->assertNotNull($caught, 'Cancellation must escape instead of returning a failed write.');
        $this->assertTrue(Coroutine::causedByCancellation($caught));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testReadThroughPromotionRetainsItsStreamAfterGoogleUploads(bool $fails): void
    {
        $uploads = 0;
        $client = new StorageClient([
            'projectId' => 'test-project',
            'credentialsFetcher' => new AnonymousCredentials,
            'retries' => 0,
            'httpHandler' => function (RequestInterface $request, array $options) use (&$uploads, $fails): Response {
                if ($request->getMethod() === 'HEAD') {
                    throw RequestException::create($request, new Response(404));
                }

                $this->assertSame('POST', $request->getMethod());
                $this->assertStringContainsString('fallback contents', (string) $request->getBody());
                ++$uploads;

                if ($fails) {
                    throw RequestException::create($request, new Response(400));
                }

                return new Response(200, ['Content-Type' => 'application/json'], '{"name":"file.txt","generation":"1"}');
            },
        ]);
        $primary = new Filesystem(new FlysystemGoogleCloudAdapter($client->bucket('test-bucket')));
        $source = fopen('php://temp', 'w+b');
        fwrite($source, 'fallback contents');
        rewind($source);
        $fallback = m::mock(FilesystemOperator::class);
        $fallback->shouldReceive('readStream')->once()->with('file.txt')->andReturn($source);
        $adapter = new ReadThroughFilesystemAdapter($primary, $fallback);
        $stream = null;

        try {
            $stream = $adapter->readStream('file.txt');

            $this->assertSame(1, $uploads);
            $this->assertIsResource($stream);
            $this->assertSame('fallback contents', stream_get_contents($stream));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    public function testUrlUsesTheConfiguredApiUriOrBucketEndpoint(): void
    {
        $client = m::mock(StorageClient::class);
        $path = 'nested/report%2F v?x#y+z.txt';
        $encodedPath = 'nested/report%252F%20v%3Fx%23y%2Bz.txt';

        $this->assertSame(
            'https://storage.googleapis.com/bucket/tenant/' . $encodedPath,
            $this->adapter($client, ['bucket' => 'bucket', 'root' => 'tenant'])->url($path),
        );
        $this->assertSame(
            'https://cdn.example.com/storage/tenant/' . $encodedPath,
            $this->adapter($client, [
                'bucket' => 'bucket',
                'root' => 'tenant',
                'storageApiUri' => 'https://cdn.example.com/storage',
            ])->url($path),
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
        $this->expectExceptionMessageIsOrContains('GCS failed');

        $adapter->readStreamRange('file.txt', 3, 5);
    }

    #[TestWith([false, false], 'direct whole read')]
    #[TestWith([false, true], 'direct range read')]
    #[TestWith([true, false], 'wrapped whole read')]
    #[TestWith([true, true], 'wrapped range read')]
    public function testReadStreamRangePreservesCancellationWithoutReporting(bool $wrapped, bool $range): void
    {
        $cancellation = new CanceledException('read canceled');
        $failure = $wrapped ? new ServiceException('SDK request canceled.', 0, $cancellation) : $cancellation;
        $exceptionHandler = m::mock(ExceptionHandler::class);
        $exceptionHandler->shouldNotReceive('report');
        Container::getInstance()->bind(ExceptionHandler::class, static fn () => $exceptionHandler);
        $object = m::mock(StorageObject::class);
        $object->shouldReceive('downloadAsStream')->once()->andThrow($failure);
        $bucket = m::mock(Bucket::class);
        $bucket->shouldReceive('object')->once()->with('file.txt')->andReturn($object);
        $client = m::mock(StorageClient::class);
        $client->shouldReceive('bucket')->once()->with('bucket')->andReturn($bucket);
        $adapter = $this->adapter($client, [
            'bucket' => 'bucket',
            'report' => true,
            'throw' => false,
        ]);

        $caught = null;

        try {
            $range ? $adapter->readStreamRange('file.txt', 3, 5) : $adapter->readStream('file.txt');
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught);
        $this->assertSame($failure, $caught);
    }

    public function testReadStreamRangeRejectsAResultWithoutAResourceWhenConfigured(): void
    {
        $stream = m::mock(StreamInterface::class);
        $stream->shouldReceive('isReadable', 'isWritable')->andReturnFalse();
        $object = m::mock(StorageObject::class);
        $object->shouldReceive('downloadAsStream')->once()->andReturn($stream);
        $bucket = m::mock(Bucket::class);
        $bucket->shouldReceive('object')->once()->with('file.txt')->andReturn($object);
        $client = m::mock(StorageClient::class);
        $client->shouldReceive('bucket')->once()->with('bucket')->andReturn($bucket);
        $adapter = $this->adapter($client, ['bucket' => 'bucket', 'throw' => true]);

        $this->expectException(UnableToReadFile::class);
        $this->expectExceptionMessageIsOrContains('The stream must be readable, writable, or both.');

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
        $stream = Utils::streamFor('contents');
        fclose($stream->detach());
        $object = m::mock(StorageObject::class);
        $object->shouldReceive('downloadAsStream')->once()->andReturn($stream);
        $bucket = m::mock(Bucket::class);
        $bucket->shouldReceive('object')->once()->with('file.txt')->andReturn($object);
        $client = m::mock(StorageClient::class);
        $client->shouldReceive('bucket')->once()->with('bucket')->andReturn($bucket);
        $adapter = $this->adapter($client, ['bucket' => 'bucket']);

        $this->assertNull($adapter->readStream('file.txt'));
    }

    #[TestWith([false], 'decoded contents')]
    #[TestWith([true], 'producer cancellation')]
    public function testDecodedReadStreamPreservesCancellationAndClosesItsBody(bool $cancel): void
    {
        $failure = new RuntimeException('Read cleanup failed.', previous: new CanceledException);
        $native = fopen('php://temp', 'w+b');
        fwrite($native, gzencode('contents'));
        rewind($native);
        $body = new InflateBodyStream($cancel
            ? new PumpStream(static fn () => throw $failure)
            : Utils::streamFor($native));
        $object = m::mock(StorageObject::class);
        $object->shouldReceive('downloadAsStream')->once()->andReturn($body);
        $bucket = m::mock(Bucket::class);
        $bucket->shouldReceive('object')->once()->with('file.gz')->andReturn($object);
        $client = m::mock(StorageClient::class);
        $client->shouldReceive('bucket')->once()->with('bucket')->andReturn($bucket);
        $adapter = $this->adapter($client, ['bucket' => 'bucket']);
        $resource = $adapter->readStream('file.gz');
        $caught = null;

        try {
            try {
                $contents = stream_get_contents($resource);
            } catch (Throwable $exception) {
                $caught = $exception;
            }

            fclose($resource);

            if ($cancel) {
                $this->assertSame($failure, $caught);
            } else {
                $this->assertNull($caught);
                $this->assertSame('contents', $contents);
                $this->assertFalse(is_resource($native));
            }
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
            $body->close();
            if (is_resource($native)) {
                fclose($native);
            }
        }
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
