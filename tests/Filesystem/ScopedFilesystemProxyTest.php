<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use BadMethodCallException;
use DateTimeImmutable;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Filesystem\Cloud;
use Hypervel\Contracts\Filesystem\Filesystem as FilesystemContract;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Filesystem\ScopedCloudFilesystemProxy;
use Hypervel\Filesystem\ScopedFilesystemProxy;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Http\UploadedFile;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use League\Flysystem\CorruptedPathDetected;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathTraversalDetected;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TypeError;

use function Hypervel\Coroutine\parallel;

class ScopedFilesystemProxyTest extends TestCase
{
    private string $tempDir;

    private FilesystemAdapter $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('ScopedFilesystemProxy');
        $adapter = new LocalFilesystemAdapter($this->tempDir);
        $this->disk = new FilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => $this->tempDir]);
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem(new LocalFilesystemAdapter(dirname($this->tempDir)));
        $filesystem->deleteDirectory(basename($this->tempDir));

        parent::tearDown();
    }

    #[DataProvider('mappedMethodProvider')]
    public function testEveryMappedMethodPrefixesExactlyOnce(
        string $method,
        array $arguments,
        array $innerArguments,
        mixed $innerResult,
        mixed $expectedResult,
    ): void {
        $inner = m::mock(FilesystemAdapter::class);
        $inner->shouldReceive($method)->once()->with(...$innerArguments)->andReturn($innerResult);
        $prefixCalls = 0;
        $proxy = new ScopedFilesystemProxy($inner, function () use (&$prefixCalls): string {
            ++$prefixCalls;

            return 'tenant';
        });

        $result = $proxy->{$method}(...$arguments);

        $this->assertSame($expectedResult, $result);
        $this->assertSame(1, $prefixCalls);
    }

    #[DataProvider('mappedMethodProvider')]
    public function testEveryMappedMethodResolvesTheDiskExactlyOnce(
        string $method,
        array $arguments,
        array $innerArguments,
        mixed $innerResult,
        mixed $expectedResult,
    ): void {
        $inner = m::mock(FilesystemAdapter::class);
        $inner->shouldReceive($method)->once()->with(...$innerArguments)->andReturn($innerResult);
        $diskCalls = 0;
        $proxy = new ScopedFilesystemProxy(
            function () use ($inner, &$diskCalls): FilesystemContract {
                ++$diskCalls;

                return $inner;
            },
            static fn (): string => 'tenant',
        );

        $result = $proxy->{$method}(...$arguments);

        $this->assertSame($expectedResult, $result);
        $this->assertSame(1, $diskCalls);
    }

    public static function mappedMethodProvider(): array
    {
        $request = Request::create('/file.txt');
        $response = new Response('response');
        $streamedResponse = new StreamedResponse;
        $expiration = new DateTimeImmutable('+1 hour');

        return [
            'path' => ['path', ['file.txt'], ['tenant/file.txt'], '/root/tenant/file.txt', '/root/tenant/file.txt'],
            'has' => ['has', ['file.txt'], ['tenant/file.txt'], true, true],
            'read' => ['read', ['file.txt'], ['tenant/file.txt'], 'contents', 'contents'],
            'fileSize' => ['fileSize', ['file.txt'], ['tenant/file.txt'], 8, 8],
            'visibility' => ['visibility', ['file.txt'], ['tenant/file.txt'], 'private', 'private'],
            'write' => ['write', ['file.txt', 'contents', ['visibility' => 'private']], ['tenant/file.txt', 'contents', ['visibility' => 'private']], null, null],
            'createDirectory' => ['createDirectory', ['dir', ['visibility' => 'private']], ['tenant/dir', ['visibility' => 'private']], null, null],
            'exists' => ['exists', ['file.txt'], ['tenant/file.txt'], true, true],
            'missing' => ['missing', ['file.txt'], ['tenant/file.txt'], false, false],
            'fileExists' => ['fileExists', ['file.txt'], ['tenant/file.txt'], true, true],
            'fileMissing' => ['fileMissing', ['file.txt'], ['tenant/file.txt'], false, false],
            'directoryExists' => ['directoryExists', ['dir'], ['tenant/dir'], true, true],
            'directoryMissing' => ['directoryMissing', ['dir'], ['tenant/dir'], false, false],
            'get' => ['get', ['file.txt'], ['tenant/file.txt'], 'contents', 'contents'],
            'json' => ['json', ['file.json', JSON_THROW_ON_ERROR], ['tenant/file.json', JSON_THROW_ON_ERROR], ['ok' => true], ['ok' => true]],
            'json scalar' => ['json', ['value.json'], ['tenant/value.json', 0], 'value', 'value'],
            'response' => ['response', ['file.txt', 'name.txt', ['X-Test' => 'yes'], 'attachment'], ['tenant/file.txt', 'name.txt', ['X-Test' => 'yes'], 'attachment'], $streamedResponse, $streamedResponse],
            'serve' => ['serve', [$request, 'file.txt', 'name.txt', ['X-Test' => 'yes']], [$request, 'tenant/file.txt', 'name.txt', ['X-Test' => 'yes']], $response, $response],
            'download' => ['download', ['file.txt', 'name.txt', ['X-Test' => 'yes']], ['tenant/file.txt', 'name.txt', ['X-Test' => 'yes']], $streamedResponse, $streamedResponse],
            'put boolean' => ['put', ['file.txt', 'contents', ['visibility' => 'private']], ['tenant/file.txt', 'contents', ['visibility' => 'private']], true, true],
            'put stored path' => ['put', ['file.txt', 'contents'], ['tenant/file.txt', 'contents', []], 'tenant/stored.txt', 'stored.txt'],
            'getVisibility' => ['getVisibility', ['file.txt'], ['tenant/file.txt'], 'private', 'private'],
            'setVisibility' => ['setVisibility', ['file.txt', 'public'], ['tenant/file.txt', 'public'], true, true],
            'prepend' => ['prepend', ['file.txt', 'before', '|'], ['tenant/file.txt', 'before', '|'], true, true],
            'append' => ['append', ['file.txt', 'after', '|'], ['tenant/file.txt', 'after', '|'], true, true],
            'delete string' => ['delete', ['file.txt'], ['tenant/file.txt'], true, true],
            'delete array' => ['delete', [['a.txt', 'b.txt']], [['tenant/a.txt', 'tenant/b.txt']], true, true],
            'copy' => ['copy', ['from.txt', 'to.txt'], ['tenant/from.txt', 'tenant/to.txt'], true, true],
            'move' => ['move', ['from.txt', 'to.txt'], ['tenant/from.txt', 'tenant/to.txt'], true, true],
            'size' => ['size', ['file.txt'], ['tenant/file.txt'], 10, 10],
            'checksum' => ['checksum', ['file.txt', ['algo' => 'sha256']], ['tenant/file.txt', ['algo' => 'sha256']], 'hash', 'hash'],
            'mimeType' => ['mimeType', ['file.txt'], ['tenant/file.txt'], 'text/plain', 'text/plain'],
            'lastModified' => ['lastModified', ['file.txt'], ['tenant/file.txt'], 123, 123],
            'readStreamRange' => ['readStreamRange', ['file.txt', 2, 4], ['tenant/file.txt', 2, 4], null, null],
            'temporaryUrl' => ['temporaryUrl', ['file.txt', $expiration, ['option' => true]], ['tenant/file.txt', $expiration, ['option' => true]], 'https://temporary', 'https://temporary'],
            'temporaryUploadUrl' => ['temporaryUploadUrl', ['file.txt', $expiration, ['option' => true]], ['tenant/file.txt', $expiration, ['option' => true]], ['url' => 'https://upload'], ['url' => 'https://upload']],
            'files at root' => ['files', [], ['tenant', false], ['tenant/a.txt', 'tenant/dir/b.txt'], ['a.txt', 'dir/b.txt']],
            'files recursive' => ['files', ['dir', true], ['tenant/dir', true], ['tenant/dir/a.txt'], ['dir/a.txt']],
            'allFiles' => ['allFiles', ['dir'], ['tenant/dir'], ['tenant/dir/a.txt'], ['dir/a.txt']],
            'directories at root' => ['directories', [], ['tenant', false], ['tenant/dir'], ['dir']],
            'directories recursive' => ['directories', ['dir', true], ['tenant/dir', true], ['tenant/dir/nested'], ['dir/nested']],
            'allDirectories' => ['allDirectories', ['dir'], ['tenant/dir'], ['tenant/dir/nested'], ['dir/nested']],
            'makeDirectory' => ['makeDirectory', ['dir'], ['tenant/dir'], true, true],
            'deleteDirectory' => ['deleteDirectory', ['dir'], ['tenant/dir'], true, true],
        ];
    }

    public function testFlysystemMethodsAndConditionableUseTheScopedBoundary(): void
    {
        $prefixCalls = 0;
        $proxy = new ScopedFilesystemProxy($this->disk, function () use (&$prefixCalls): string {
            ++$prefixCalls;

            return 'tenant';
        });

        $this->assertSame($proxy, $proxy->when(true, function (ScopedFilesystemProxy $filesystem): ScopedFilesystemProxy {
            $filesystem->write('raw.txt', 'contents', ['visibility' => 'private']);

            return $filesystem;
        }));
        $this->assertTrue($proxy->has('raw.txt'));
        $this->assertSame('contents', $proxy->read('raw.txt'));
        $this->assertSame(8, $proxy->fileSize('raw.txt'));
        $this->assertSame('private', $proxy->visibility('raw.txt'));
        $proxy->createDirectory('raw-directory');
        $this->assertSame($proxy, $proxy->unless(false, function (ScopedFilesystemProxy $filesystem): ScopedFilesystemProxy {
            $this->assertSame('contents', $filesystem->read('raw.txt'));

            return $filesystem;
        }));

        $this->assertSame(7, $prefixCalls);
        $this->assertSame('contents', $this->disk->read('tenant/raw.txt'));
        $this->assertTrue($this->disk->directoryExists('tenant/raw-directory'));
        $this->assertFalse($this->disk->exists('raw.txt'));
    }

    public function testReadAndWriteStreamsAreMappedWithoutConsumingDeferredResults(): void
    {
        $inner = m::mock(FilesystemAdapter::class);
        $readStream = fopen('php://temp', 'r+');
        $writeStream = fopen('php://temp', 'r+');
        $this->assertIsResource($readStream);
        $this->assertIsResource($writeStream);
        $inner->shouldReceive('readStream')->once()->with('tenant/read.txt')->andReturn($readStream);
        $inner->shouldReceive('writeStream')->once()->with('tenant/write.txt', $writeStream, ['visibility' => 'private'])->andReturnTrue();
        $prefixCalls = 0;
        $proxy = new ScopedFilesystemProxy($inner, function () use (&$prefixCalls): string {
            ++$prefixCalls;

            return 'tenant';
        });

        $this->assertSame($readStream, $proxy->readStream('read.txt'));
        $this->assertSame(1, $prefixCalls);
        $this->assertTrue($proxy->writeStream('write.txt', $writeStream, ['visibility' => 'private']));
        $this->assertSame(2, $prefixCalls);

        fclose($readStream);
        fclose($writeStream);
    }

    public function testPutFileOverloadsPrefixAndStripReturnedPaths(): void
    {
        $path = $this->tempDir . '/upload.txt';
        file_put_contents($path, 'upload');
        $upload = new UploadedFile($path, 'upload.txt', test: true);
        $inner = m::mock(FilesystemAdapter::class);
        $inner->shouldReceive('putFile')
            ->once()
            ->with('tenant', $upload, [])
            ->andReturn('tenant/generated.txt');
        $inner->shouldReceive('putFileAs')
            ->once()
            ->with('tenant/named.txt', $upload, '', [])
            ->andReturn('tenant/named.txt');
        $prefixCalls = 0;
        $proxy = new ScopedFilesystemProxy($inner, function () use (&$prefixCalls): string {
            ++$prefixCalls;

            return 'tenant';
        });

        $this->assertSame('generated.txt', $proxy->putFile($upload));
        $this->assertSame(1, $prefixCalls);
        $this->assertSame('named.txt', $proxy->putFileAs($upload, 'named.txt'));
        $this->assertSame(2, $prefixCalls);
    }

    public function testPutFileAsRejectsFinalTargetEscapeBeforeWriting(): void
    {
        $path = $this->tempDir . '/upload.txt';
        file_put_contents($path, 'upload');
        $upload = new UploadedFile($path, 'upload.txt', test: true);
        $proxy = new ScopedFilesystemProxy($this->disk, static fn (): string => 'tenant');

        try {
            $proxy->putFileAs('dir', $upload, '../../evil.txt');
            $this->fail('Expected the final upload target to reject traversal.');
        } catch (PathTraversalDetected) {
            $this->assertFalse($this->disk->exists('evil.txt'));
        }

        $this->assertSame('safe.txt', $proxy->putFileAs('dir', $upload, '../safe.txt'));
        $this->assertTrue($this->disk->exists('tenant/safe.txt'));
    }

    public function testNoPathMethodsDoNotResolveThePrefix(): void
    {
        $inner = m::mock(FilesystemAdapter::class);
        $inner->shouldReceive('providesTemporaryUrls')->once()->andReturnTrue();
        $inner->shouldReceive('providesTemporaryUploadUrls')->once()->andReturnFalse();
        $inner->shouldReceive('getConfig')->once()->andReturn(['driver' => 'local']);
        $proxy = new ScopedFilesystemProxy(
            $inner,
            static fn (): never => throw new RuntimeException('prefix must not be resolved'),
        );

        $this->assertTrue($proxy->providesTemporaryUrls());
        $this->assertFalse($proxy->providesTemporaryUploadUrls());
        $this->assertSame(['driver' => 'local'], $proxy->getConfig());
    }

    public function testNoPathMethodsResolveTheDiskOncePerCallWithoutResolvingThePrefix(): void
    {
        $inner = m::mock(FilesystemAdapter::class);
        $inner->shouldReceive('providesTemporaryUrls')->once()->andReturnTrue();
        $inner->shouldReceive('providesTemporaryUploadUrls')->once()->andReturnFalse();
        $inner->shouldReceive('getConfig')->once()->andReturn(['driver' => 'local']);
        $diskCalls = 0;
        $proxy = new ScopedFilesystemProxy(
            function () use ($inner, &$diskCalls): FilesystemContract {
                ++$diskCalls;

                return $inner;
            },
            static fn (): never => throw new RuntimeException('prefix must not be resolved'),
        );

        $this->assertTrue($proxy->providesTemporaryUrls());
        $this->assertFalse($proxy->providesTemporaryUploadUrls());
        $this->assertSame(['driver' => 'local'], $proxy->getConfig());
        $this->assertSame(3, $diskCalls);
    }

    public function testEveryAssertionMapsPathsAndReturnsTheScopedProxy(): void
    {
        $inner = m::mock(FilesystemAdapter::class);
        $inner->shouldReceive('assertExists')->once()->with(['tenant/a.txt', 'tenant/b.txt'], 'content')->andReturnSelf();
        $inner->shouldReceive('assertMissing')->once()->with(['tenant/c.txt', 'tenant/d.txt'])->andReturnSelf();
        $inner->shouldReceive('assertCount')->once()->with('tenant/dir', 2, true)->andReturnSelf();
        $inner->shouldReceive('assertDirectoryEmpty')->once()->with('tenant/empty')->andReturnSelf();
        $inner->shouldReceive('assertDirectoryEmpty')->once()->with('tenant')->andReturnSelf();
        $prefixCalls = 0;
        $proxy = new ScopedFilesystemProxy($inner, function () use (&$prefixCalls): string {
            ++$prefixCalls;

            return 'tenant';
        });

        $this->assertSame($proxy, $proxy->assertExists(['a.txt', 'b.txt'], 'content'));
        $this->assertSame($proxy, $proxy->assertMissing(['c.txt', 'd.txt']));
        $this->assertSame($proxy, $proxy->assertCount('dir', 2, true));
        $this->assertSame($proxy, $proxy->assertDirectoryEmpty('empty'));
        $this->assertSame($proxy, $proxy->assertEmpty());
        $this->assertSame(5, $prefixCalls);
    }

    #[DataProvider('emptyPrefixProvider')]
    public function testNormalizedEmptyPrefixesFailClosed(string $prefix): void
    {
        $proxy = new ScopedFilesystemProxy($this->disk, static fn (): string => $prefix);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('returned an empty prefix');

        $proxy->exists('file.txt');
    }

    public static function emptyPrefixProvider(): array
    {
        return [
            'empty' => [''],
            'current directory' => ['.'],
            'resolved traversal' => ['tenant/..'],
        ];
    }

    public function testRootPassthroughMustBeExplicit(): void
    {
        $this->disk->put('file.txt', 'contents');
        $proxy = new ScopedFilesystemProxy($this->disk, static fn (): string => '.', true);

        $this->assertSame('contents', $proxy->get('file.txt'));
    }

    #[DataProvider('traversalProvider')]
    public function testTraversalAndCorruptedPathsAreRejected(string $path, string $exception): void
    {
        $proxy = new ScopedFilesystemProxy($this->disk, static fn (): string => 'tenant');

        $this->expectException($exception);

        $proxy->exists($path);
    }

    public static function traversalProvider(): array
    {
        return [
            'parent traversal' => ['../secret', PathTraversalDetected::class],
            'backslash traversal' => ['..\secret', PathTraversalDetected::class],
            'nul' => ["file\0.txt", CorruptedPathDetected::class],
            'control character' => ["file\x1F.txt", CorruptedPathDetected::class],
        ];
    }

    public function testPrefixTraversalIsRejected(): void
    {
        $proxy = new ScopedFilesystemProxy($this->disk, static fn (): string => '../tenant');

        $this->expectException(PathTraversalDetected::class);

        $proxy->exists('file.txt');
    }

    public function testInternalTraversalNormalizesWhilePercentEncodingRemainsLiteral(): void
    {
        $inner = m::mock(FilesystemAdapter::class);
        $inner->shouldReceive('exists')->once()->with('tenant/b.txt')->andReturnTrue();
        $inner->shouldReceive('exists')->once()->with('tenant/%2e%2e%2fsecret')->andReturnTrue();
        $proxy = new ScopedFilesystemProxy($inner, static fn (): string => 'tenant');

        $this->assertTrue($proxy->exists('a/../b.txt'));
        $this->assertTrue($proxy->exists('%2e%2e%2fsecret'));
    }

    #[DataProvider('foreignReturnedPathProvider')]
    public function testReturnedPathsOutsideTheNormalizedPrefixFailClosed(string $returned): void
    {
        $inner = m::mock(FilesystemAdapter::class);
        $inner->shouldReceive('files')->once()->with('tenant', false)->andReturn([$returned]);
        $proxy = new ScopedFilesystemProxy($inner, static fn (): string => 'tenant');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside the resolved prefix');

        $proxy->files();
    }

    public static function foreignReturnedPathProvider(): array
    {
        return [
            'foreign path' => ['outside/file.txt'],
            'normalized escape' => ['tenant/../outside'],
        ];
    }

    public function testPrefixIsResolvedPerOperationAndIsolatedAcrossCoroutines(): void
    {
        $proxy = new ScopedFilesystemProxy(
            $this->disk,
            static fn (): string => CoroutineContext::get('filesystem.scope'),
        );

        parallel([
            function () use ($proxy): void {
                CoroutineContext::set('filesystem.scope', 'first');
                usleep(5000);
                $proxy->put('file.txt', 'first');
            },
            function () use ($proxy): void {
                CoroutineContext::set('filesystem.scope', 'second');
                usleep(5000);
                $proxy->put('file.txt', 'second');
            },
        ]);

        $this->assertSame('first', $this->disk->get('first/file.txt'));
        $this->assertSame('second', $this->disk->get('second/file.txt'));
    }

    public function testDiskIsResolvedPerOperation(): void
    {
        $first = m::mock(FilesystemContract::class);
        $first->shouldReceive('get')->once()->with('tenant/file.txt')->andReturn('first');
        $second = m::mock(FilesystemContract::class);
        $second->shouldReceive('get')->once()->with('tenant/file.txt')->andReturn('second');
        $current = $first;
        $diskCalls = 0;
        $proxy = new ScopedFilesystemProxy(
            function () use (&$current, &$diskCalls): FilesystemContract {
                ++$diskCalls;

                return $current;
            },
            static fn (): string => 'tenant',
        );

        $this->assertSame('first', $proxy->get('file.txt'));
        $current = $second;
        $this->assertSame('second', $proxy->get('file.txt'));
        $this->assertSame(2, $diskCalls);
    }

    public function testDiskResolverIsIsolatedAcrossCoroutines(): void
    {
        $firstAdapter = new LocalFilesystemAdapter($this->tempDir . '/first-disk');
        $first = new FilesystemAdapter(
            new Filesystem($firstAdapter),
            $firstAdapter,
            ['root' => $this->tempDir . '/first-disk'],
        );
        $secondAdapter = new LocalFilesystemAdapter($this->tempDir . '/second-disk');
        $second = new FilesystemAdapter(
            new Filesystem($secondAdapter),
            $secondAdapter,
            ['root' => $this->tempDir . '/second-disk'],
        );
        $proxy = new ScopedFilesystemProxy(
            static fn (): FilesystemContract => match (CoroutineContext::get('filesystem.disk')) {
                'first' => $first,
                'second' => $second,
            },
            static fn (): string => 'tenant',
        );

        parallel([
            function () use ($proxy): void {
                CoroutineContext::set('filesystem.disk', 'first');
                usleep(5000);
                $proxy->put('file.txt', 'first');
            },
            function () use ($proxy): void {
                CoroutineContext::set('filesystem.disk', 'second');
                usleep(5000);
                $proxy->put('file.txt', 'second');
            },
        ]);

        $this->assertSame('first', $first->get('tenant/file.txt'));
        $this->assertSame('second', $second->get('tenant/file.txt'));
    }

    public function testWrongResolverReturnTypeFailsNaturally(): void
    {
        $proxy = new ScopedFilesystemProxy($this->disk, static fn (): array => []);

        $this->expectException(TypeError::class);

        $proxy->exists('file.txt');
    }

    public function testWrongDiskResolverReturnTypeFailsNaturally(): void
    {
        $proxy = new ScopedFilesystemProxy(
            static fn (): array => [],
            static fn (): string => 'tenant',
        );

        $this->expectException(TypeError::class);

        $proxy->exists('file.txt');
    }

    #[DataProvider('rejectedMethodProvider')]
    public function testInternalsAndSharedStateMutatorsAreRejected(string $method): void
    {
        $proxy = new ScopedFilesystemProxy($this->disk, static fn (): string => 'tenant');

        $this->expectException(RuntimeException::class);
        $proxy->{$method}(null);
    }

    public static function rejectedMethodProvider(): array
    {
        return [
            'driver' => ['getDriver'],
            'adapter' => ['getAdapter'],
            'client' => ['getClient'],
            'serve callback' => ['serveUsing'],
            'temporary URL callback' => ['buildTemporaryUrlsUsing'],
            'temporary upload callback' => ['buildTemporaryUploadUrlsUsing'],
        ];
    }

    public function testUnknownMethodsAndUnsupportedInnerCapabilitiesAreRejected(): void
    {
        $proxy = new ScopedFilesystemProxy($this->disk, static fn (): string => 'tenant');

        try {
            $proxy->listContents('');
            $this->fail('Expected the unknown method to be rejected.');
        } catch (BadMethodCallException $exception) {
            $this->assertStringContainsString('unmapped calls could bypass', $exception->getMessage());
        }

        $inner = m::mock(FilesystemContract::class);
        $proxy = new ScopedFilesystemProxy($inner, static fn (): string => 'tenant');

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('does not support [missing]');
        $proxy->missing('file.txt');
    }

    public function testDiskResolverCannotExposeInternalsOrUnknownMethods(): void
    {
        $diskCalls = 0;
        $proxy = new ScopedFilesystemProxy(
            function () use (&$diskCalls): FilesystemContract {
                ++$diskCalls;

                return $this->disk;
            },
            static fn (): string => 'tenant',
        );

        try {
            $proxy->getDriver();
            $this->fail('Expected inner driver access to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unscoped internals', $exception->getMessage());
        }

        try {
            $proxy->listContents('');
            $this->fail('Expected the unknown method to be rejected.');
        } catch (BadMethodCallException $exception) {
            $this->assertStringContainsString('unmapped calls could bypass', $exception->getMessage());
        }

        $this->assertSame(0, $diskCalls);
    }

    public function testResolvedDiskWithoutMappedCapabilityIsRejected(): void
    {
        $inner = m::mock(FilesystemContract::class);
        $proxy = new ScopedFilesystemProxy(
            static fn (): FilesystemContract => $inner,
            static fn (): string => 'tenant',
        );

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('does not support [missing]');

        $proxy->missing('file.txt');
    }

    public function testCloudVariantMapsUrlAndResolvesPrefixOnce(): void
    {
        $inner = m::mock(Cloud::class);
        $inner->shouldReceive('url')->once()->with('tenant/file.txt')->andReturn('https://example.test/file.txt');
        $prefixCalls = 0;
        $proxy = new ScopedCloudFilesystemProxy($inner, function () use (&$prefixCalls): string {
            ++$prefixCalls;

            return 'tenant';
        });

        $this->assertSame('https://example.test/file.txt', $proxy->url('file.txt'));
        $this->assertSame(1, $prefixCalls);
    }

    public function testCloudVariantResolvesTheDiskOnce(): void
    {
        $inner = m::mock(Cloud::class);
        $inner->shouldReceive('url')->once()->with('tenant/file.txt')->andReturn('https://example.test/file.txt');
        $diskCalls = 0;
        $proxy = new ScopedCloudFilesystemProxy(
            function () use ($inner, &$diskCalls): Cloud {
                ++$diskCalls;

                return $inner;
            },
            static fn (): string => 'tenant',
        );

        $this->assertSame('https://example.test/file.txt', $proxy->url('file.txt'));
        $this->assertSame(1, $diskCalls);
    }

    public function testCloudVariantRejectsANonCloudResolvedDisk(): void
    {
        $inner = m::mock(FilesystemContract::class);
        $proxy = new ScopedCloudFilesystemProxy(
            static fn (): FilesystemContract => $inner,
            static fn (): string => 'tenant',
        );

        $this->expectException(TypeError::class);

        $proxy->url('file.txt');
    }
}
