<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use Carbon\Carbon;
use GuzzleHttp\Psr7\Stream;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Coroutine\Coroutine;
use Hyperf\HttpMessage\Upload\UploadedFile;
use Hypervel\Contracts\Http\Request as RequestContract;
use Hypervel\Contracts\Http\Response as ResponseContract;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Filesystem\FilesystemManager;
use Hypervel\Http\Response;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use Mockery as m;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Swoole\Runtime;

/**
 * @internal
 * @coversNothing
 */
class FilesystemAdapterTest extends TestCase
{
    private $tempDir;

    private $filesystem;

    private $adapter;

    protected function setUp(): void
    {
        $this->tempDir = __DIR__ . '/tmp';
        $this->filesystem = new Filesystem(
            $this->adapter = new LocalFilesystemAdapter($this->tempDir)
        );
    }

    protected function tearDown(): void
    {
        if (Coroutine::inCoroutine()) {
            Runtime::enableCoroutine(0);
        }

        $filesystem = new Filesystem(
            $this->adapter = new LocalFilesystemAdapter(dirname($this->tempDir))
        );
        $filesystem->deleteDirectory(basename($this->tempDir));

        unset($this->tempDir, $this->filesystem, $this->adapter);
    }

    public function testResponse()
    {
        $this->mockResponse($content = 'Hello World');

        $this->filesystem->write('file.txt', $content);
        $files = new FilesystemAdapter($this->filesystem, $this->adapter);
        $response = $files->response('file.txt');

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals([
            'Content-Type' => ['text/plain'],
            'Content-Disposition' => ['inline; filename=file.txt'],
        ], $response->getHeaders());
    }

    public function testMimeTypeIsNotCalledAlreadyProvidedToResponse()
    {
        $this->mockResponse($content = 'Hello World');

        $this->filesystem->write('file.txt', $content);

        $files = m::mock(FilesystemAdapter::class, [$this->filesystem, $this->adapter])->makePartial();
        $files->shouldReceive('mimeType')->never();

        $files->response('file.txt', null, [
            'Content-Type' => 'text/x-custom',
        ]);
    }

    public function testFallbackNameCalledAlreadyProvidedToResponse()
    {
        $this->mockResponse($content = 'Hello World');

        $this->filesystem->write('file.txt', $content);

        $files = m::mock(FilesystemAdapter::class, [$this->filesystem, $this->adapter])
            ->shouldAllowMockingProtectedMethods()
            ->makePartial();
        $files->shouldReceive('fallbackName')->never();

        $files->response('file.txt', null, [
            'Content-Disposition' => 'attachment',
        ]);
    }

    public function testDownload()
    {
        $this->mockResponse($content = 'Hello World');

        $this->filesystem->write('file.txt', $content);
        $files = new FilesystemAdapter($this->filesystem, $this->adapter);
        $response = $files->download('file.txt', 'hello.txt');
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame('attachment; filename=hello.txt', $response->getHeader('content-disposition')[0]);
    }

    public function testDownloadNonAsciiFilename()
    {
        $this->mockResponse($content = 'Hello World');

        $this->filesystem->write('file.txt', $content);
        $files = new FilesystemAdapter($this->filesystem, $this->adapter);
        $response = $files->download('file.txt', 'привет.txt');
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame("attachment; filename=privet.txt; filename*=utf-8''%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82.txt", $response->getHeader('content-disposition')[0]);
    }

    public function testDownloadNonAsciiEmptyFilename()
    {
        $this->mockResponse($content = 'Hello World');

        $this->filesystem->write('привет.txt', $content);
        $files = new FilesystemAdapter($this->filesystem, $this->adapter);
        $response = $files->download('привет.txt');
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame('attachment; filename=privet.txt; filename*=utf-8\'\'%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82.txt', $response->getHeader('content-disposition')[0]);
    }

    public function testDownloadPercentInFilename()
    {
        $this->mockResponse($content = 'Hello World');

        $this->filesystem->write('Hello%World.txt', $content);
        $files = new FilesystemAdapter($this->filesystem, $this->adapter);
        $response = $files->download('Hello%World.txt', 'Hello%World.txt');
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame('attachment; filename=HelloWorld.txt; filename*=utf-8\'\'Hello%25World.txt', $response->getHeader('content-disposition')[0]);
    }

    public function testExists()
    {
        $this->filesystem->write('file.txt', 'Hello World');
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $this->assertTrue($filesystemAdapter->exists('file.txt'));
        $this->assertTrue($filesystemAdapter->fileExists('file.txt'));
    }

    public function testMissing()
    {
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $this->assertTrue($filesystemAdapter->missing('file.txt'));
        $this->assertTrue($filesystemAdapter->fileMissing('file.txt'));
    }

    public function testDirectoryExists()
    {
        $this->filesystem->write('/foo/bar/file.txt', 'Hello World');
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $this->assertTrue($filesystemAdapter->directoryExists('/foo/bar'));
    }

    public function testDirectoryMissing()
    {
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $this->assertTrue($filesystemAdapter->directoryMissing('/foo/bar'));
    }

    public function testPath()
    {
        $this->filesystem->write('file.txt', 'Hello World');
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter, [
            'root' => $this->tempDir . DIRECTORY_SEPARATOR,
        ]);
        $this->assertEquals($this->tempDir . DIRECTORY_SEPARATOR . 'file.txt', $filesystemAdapter->path('file.txt'));
    }

    public function testGet()
    {
        $this->filesystem->write('file.txt', 'Hello World');
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $this->assertSame('Hello World', $filesystemAdapter->get('file.txt'));
    }

    public function testGetFileNotFound()
    {
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $this->assertNull($filesystemAdapter->get('file.txt'));
    }

    public function testJsonReturnsDecodedJsonData()
    {
        $this->filesystem->write('file.json', '{"foo": "bar"}');
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $this->assertSame(['foo' => 'bar'], $filesystemAdapter->json('file.json'));
    }

    public function testJsonReturnsNullIfJsonDataIsInvalid()
    {
        $this->filesystem->write('file.json', '{"foo":');
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $this->assertNull($filesystemAdapter->json('file.json'));
    }

    public function testMimeTypeNotDetected()
    {
        $this->filesystem->write('unknown.mime-type', '');
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $this->assertFalse($filesystemAdapter->mimeType('unknown.mime-type'));
    }

    public function testPut()
    {
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $filesystemAdapter->put('file.txt', 'Something inside');
        $this->assertStringEqualsFile($this->tempDir . '/file.txt', 'Something inside');
    }

    public function testPrepend()
    {
        file_put_contents($this->tempDir . '/file.txt', 'World');
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $filesystemAdapter->prepend('file.txt', 'Hello ');
        $this->assertStringEqualsFile($this->tempDir . '/file.txt', 'Hello ' . PHP_EOL . 'World');
    }

    public function testAppend()
    {
        file_put_contents($this->tempDir . '/file.txt', 'Hello ');
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $filesystemAdapter->append('file.txt', 'Moon');
        $this->assertStringEqualsFile($this->tempDir . '/file.txt', 'Hello ' . PHP_EOL . 'Moon');
    }

    public function testDelete()
    {
        file_put_contents($this->tempDir . '/file.txt', 'Hello World');
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $this->assertTrue($filesystemAdapter->delete('file.txt'));
        $this->assertFileDoesNotExist($this->tempDir . '/file.txt');
    }

    public function testDeleteReturnsTrueWhenFileNotFound()
    {
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $this->assertTrue($filesystemAdapter->delete('file.txt'));
    }

    public function testCopy()
    {
        $data = '33232';
        mkdir($this->tempDir . '/foo');
        file_put_contents($this->tempDir . '/foo/foo.txt', $data);

        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $filesystemAdapter->copy('/foo/foo.txt', '/foo/foo2.txt');

        $this->assertFileExists($this->tempDir . '/foo/foo.txt');
        $this->assertEquals($data, file_get_contents($this->tempDir . '/foo/foo.txt'));

        $this->assertFileExists($this->tempDir . '/foo/foo2.txt');
        $this->assertEquals($data, file_get_contents($this->tempDir . '/foo/foo2.txt'));
    }

    public function testMove()
    {
        $data = '33232';
        mkdir($this->tempDir . '/foo');
        file_put_contents($this->tempDir . '/foo/foo.txt', $data);

        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $filesystemAdapter->move('/foo/foo.txt', '/foo/foo2.txt');

        $this->assertFileDoesNotExist($this->tempDir . '/foo/foo.txt');

        $this->assertFileExists($this->tempDir . '/foo/foo2.txt');
        $this->assertEquals($data, file_get_contents($this->tempDir . '/foo/foo2.txt'));
    }

    public function testStream()
    {
        $this->filesystem->write('file.txt', $original_content = 'Hello World');
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $readStream = $filesystemAdapter->readStream('file.txt');
        $filesystemAdapter->writeStream('copy.txt', $readStream);
        $this->assertEquals($original_content, $filesystemAdapter->get('copy.txt'));
    }

    public function testStreamBetweenFilesystems()
    {
        $secondFilesystem = new Filesystem(new LocalFilesystemAdapter($this->tempDir . '/second'));
        $this->filesystem->write('file.txt', $original_content = 'Hello World');
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $secondFilesystemAdapter = new FilesystemAdapter($secondFilesystem, $this->adapter);
        $readStream = $filesystemAdapter->readStream('file.txt');
        $secondFilesystemAdapter->writeStream('copy.txt', $readStream);
        $this->assertEquals($original_content, $secondFilesystemAdapter->get('copy.txt'));
    }

    public function testStreamToExistingFileOverwrites()
    {
        $this->filesystem->write('file.txt', 'Hello World');
        $this->filesystem->write('existing.txt', 'Dear Kate');
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $readStream = $filesystemAdapter->readStream('file.txt');
        $filesystemAdapter->writeStream('existing.txt', $readStream);
        $this->assertSame('Hello World', $filesystemAdapter->read('existing.txt'));
    }

    public function testReadStreamNonExistentFileReturnsNull()
    {
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $this->assertNull($filesystemAdapter->readStream('nonexistent.txt'));
    }

    public function testStreamInvalidResourceThrows()
    {
        $this->expectException(InvalidArgumentException::class);
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $filesystemAdapter->writeStream('file.txt', 'foo bar');
    }

    public function testPutWithStreamInterface()
    {
        file_put_contents($this->tempDir . '/foo.txt', 'some-data');

        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $stream = fopen($this->tempDir . '/foo.txt', 'r');
        $guzzleStream = new Stream($stream);
        $filesystemAdapter->put('bar.txt', $guzzleStream);
        fclose($stream);

        $this->assertSame('some-data', $filesystemAdapter->get('bar.txt'));
    }

    public function testPutFileAs()
    {
        file_put_contents($filePath = $this->tempDir . '/foo.txt', 'uploaded file content');

        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);

        $uploadedFile = new UploadedFile($filePath, 10, UPLOAD_ERR_OK);

        $storagePath = $filesystemAdapter->putFileAs('/', $uploadedFile, 'new.txt');

        $this->assertSame('new.txt', $storagePath);

        $this->assertFileExists($filePath);

        $filesystemAdapter->assertExists($storagePath);

        $this->assertSame('uploaded file content', $filesystemAdapter->read($storagePath));

        $filesystemAdapter->assertExists(
            $storagePath,
            'uploaded file content'
        );
    }

    public function testPutFileAsWithAbsoluteFilePath()
    {
        file_put_contents($filePath = $this->tempDir . '/foo.txt', 'normal file content');

        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);

        $storagePath = $filesystemAdapter->putFileAs('/', $filePath, 'new.txt');

        $this->assertSame('normal file content', $filesystemAdapter->read($storagePath));
    }

    public function testPutFileAsWithoutPath()
    {
        file_put_contents($filePath = $this->tempDir . '/foo.txt', 'normal file content');

        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);

        $storagePath = $filesystemAdapter->putFileAs($filePath, 'new.txt');

        $this->assertSame('normal file content', $filesystemAdapter->read($storagePath));
    }

    public function testPutFile()
    {
        file_put_contents($filePath = $this->tempDir . '/foo.txt', 'uploaded file content');

        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);

        $uploadedFile = new UploadedFile($filePath, 10, UPLOAD_ERR_OK);

        $storagePath = $filesystemAdapter->putFile('/', $uploadedFile);

        $this->assertSame(44, strlen($storagePath)); // random 40 characters + ".txt"

        $this->assertFileExists($filePath);

        $filesystemAdapter->assertExists($storagePath);

        $filesystemAdapter->assertExists(
            $storagePath,
            'uploaded file content'
        );
    }

    public function testPutFileWithAbsoluteFilePath()
    {
        file_put_contents($filePath = $this->tempDir . '/foo.txt', 'uploaded file content');

        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);

        $storagePath = $filesystemAdapter->putFile('/', $filePath);

        $this->assertSame(44, strlen($storagePath)); // random 40 characters + ".txt"

        $filesystemAdapter->assertExists($storagePath);

        $filesystemAdapter->assertExists(
            $storagePath,
            'uploaded file content'
        );
    }

    public function testPutFileWithoutPath()
    {
        file_put_contents($filePath = $this->tempDir . '/foo.txt', 'normal file content');

        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);

        $storagePath = $filesystemAdapter->putFile($filePath);

        $this->assertSame('normal file content', $filesystemAdapter->read($storagePath));
    }

    #[RequiresPhpExtension('ftp')]
    public function testCreateFtpDriver()
    {
        if (! class_exists(FtpAdapter::class)) {
            $this->markTestSkipped('league/flysystem-ftp is not installed.');
        }

        $filesystem = new FilesystemManager(m::mock(ContainerInterface::class));

        $driver = $filesystem->createFtpDriver([
            'host' => 'ftp.example.com',
            'username' => 'admin',
            'permPublic' => 0700,
            'unsupportedParam' => true,
        ]);

        $this->assertInstanceOf(FtpAdapter::class, $driver->getAdapter());

        $config = $driver->getConfig();
        $this->assertEquals(0700, $config['permPublic']);
        $this->assertSame('ftp.example.com', $config['host']);
        $this->assertSame('admin', $config['username']);
    }

    public function testMacroable()
    {
        $this->filesystem->write('foo.txt', 'Hello World');

        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $filesystemAdapter->macro('getFoo', function () {
            return $this->get('foo.txt');
        });

        $this->assertSame('Hello World', $filesystemAdapter->getFoo());
    }

    public function testTemporaryUrlWithCustomCallback()
    {
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);

        $filesystemAdapter->buildTemporaryUrlsUsing(function ($path, Carbon $expiration, $options) {
            return $path . $expiration->toString() . implode('', $options);
        });

        $path = 'foo';
        $expiration = Carbon::create(2021, 18, 12, 13);
        $options = ['bar' => 'baz'];

        $this->assertSame(
            $path . $expiration->toString() . implode('', $options),
            $filesystemAdapter->temporaryUrl($path, $expiration, $options)
        );
    }

    public function testThrowExceptionsForGet()
    {
        $adapter = new FilesystemAdapter($this->filesystem, $this->adapter, ['throw' => true]);

        try {
            $adapter->get('/foo.txt');
        } catch (UnableToReadFile) {
            $this->assertTrue(true);

            return;
        }

        $this->fail('Exception was not thrown.');
    }

    public function testThrowExceptionsForReadStream()
    {
        $adapter = new FilesystemAdapter($this->filesystem, $this->adapter, ['throw' => true]);

        try {
            $adapter->readStream('/foo.txt');
        } catch (UnableToReadFile) {
            $this->assertTrue(true);

            return;
        }

        $this->fail('Exception was not thrown.');
    }

    public function testThrowExceptionsForPut()
    {
        if (trim(shell_exec('whoami')) === 'root') {
            $this->markTestSkipped('Cannot test file permissions as root user.');
            return;
        }

        $this->filesystem->write('foo.txt', 'Hello World');

        chmod(__DIR__ . '/tmp/foo.txt', 0400);

        $adapter = new FilesystemAdapter($this->filesystem, $this->adapter, ['throw' => true]);

        try {
            $adapter->put('/foo.txt', 'Hello World!');
        } catch (UnableToWriteFile) {
            $this->assertTrue(true);

            return;
        } finally {
            chmod(__DIR__ . '/tmp/foo.txt', 0600);
        }

        $this->fail('Exception was not thrown.');
    }

    public function testThrowExceptionsForMimeType()
    {
        $this->filesystem->write('unknown.mime-type', '');

        $adapter = new FilesystemAdapter($this->filesystem, $this->adapter, ['throw' => true]);

        try {
            $adapter->mimeType('unknown.mime-type');
        } catch (UnableToRetrieveMetadata) {
            $this->assertTrue(true);

            return;
        }

        $this->fail('Exception was not thrown.');
    }

    public function testGetAllFiles()
    {
        $this->filesystem->write('body.txt', 'Hello World');
        $this->filesystem->write('file1.txt', 'Hello World');
        $this->filesystem->write('file.txt', 'Hello World');
        $this->filesystem->write('existing.txt', 'Dear Kate');

        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);

        $this->assertSame($filesystemAdapter->files(), ['body.txt', 'existing.txt', 'file.txt', 'file1.txt']);
    }

    public function testProvidesTemporaryUrls()
    {
        $localAdapter = new class($this->tempDir) extends LocalFilesystemAdapter {
            public function getTemporaryUrl($path, Carbon $expiration, $options): string
            {
                return $path . $expiration->toString() . implode('', $options);
            }
        };
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $localAdapter);

        $this->assertTrue($filesystemAdapter->providesTemporaryUrls());
    }

    public function testProvidesTemporaryUrlsWithCustomCallback()
    {
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);

        $filesystemAdapter->buildTemporaryUrlsUsing(function ($path, Carbon $expiration, $options) {
            return $path . $expiration->toString() . implode('', $options);
        });

        $this->assertTrue($filesystemAdapter->providesTemporaryUrls());
    }

    public function testProvidesTemporaryUrlsForS3Adapter()
    {
        $filesystem = new FilesystemManager(m::mock(ContainerInterface::class));
        $filesystemAdapter = $filesystem->createS3Driver([
            'region' => 'us-west-1',
            'bucket' => 'laravel',
        ]);

        $this->assertTrue($filesystemAdapter->providesTemporaryUrls());
    }

    public function testProvidesTemporaryUrlsForAdapterWithoutTemporaryUrlSupport()
    {
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);

        $this->assertFalse($filesystemAdapter->providesTemporaryUrls());
    }

    public function testPrefixesUrls()
    {
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter, ['url' => 'https://example.org/', 'prefix' => 'images']);

        $this->assertEquals('https://example.org/images/picture.jpeg', $filesystemAdapter->url('picture.jpeg'));
    }

    public function testGetChecksum()
    {
        $filesystemAdapter = new FilesystemAdapter($this->filesystem, $this->adapter);
        $filesystemAdapter->write('path.txt', 'contents of file');

        $this->assertEquals('730bed78bccf58c2cfe44c29b71e5e6b', $filesystemAdapter->checksum('path.txt'));
        $this->assertEquals('a5c3556d', $filesystemAdapter->checksum('path.txt', ['checksum_algo' => 'crc32']));
    }

    protected function mockResponse(string $content): void
    {
        $container = m::mock(ContainerInterface::class);
        $container->shouldReceive('get')
            ->with(ResponseContract::class)
            ->andReturn(new Response());
        $request = m::mock(RequestContract::class);
        $request->shouldReceive('isRange')
            ->andReturn(false);
        $container->shouldReceive('get')
            ->with(RequestContract::class)
            ->andReturn($request);
        ApplicationContext::setContainer($container);

        $psrResponse = m::mock(\Hyperf\HttpMessage\Server\Response::class)->makePartial();
        $psrResponse->shouldReceive('write')
            ->with($content)
            ->once()
            ->andReturnTrue();
        Context::set(ResponseInterface::class, $psrResponse);
    }
}
