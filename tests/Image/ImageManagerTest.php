<?php

declare(strict_types=1);

namespace Hypervel\Tests\Image;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Filesystem\Factory as FilesystemFactory;
use Hypervel\Contracts\Filesystem\Filesystem as FilesystemContract;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Image\Driver;
use Hypervel\Contracts\Image\Transformation;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Client\Factory as HttpFactory;
use Hypervel\Http\Client\RequestException;
use Hypervel\Http\Client\Response as ClientResponse;
use Hypervel\Http\UploadedFile;
use Hypervel\Image\Drivers\InterventionDriver;
use Hypervel\Image\Image;
use Hypervel\Image\ImageException;
use Hypervel\Image\ImageManager;
use Hypervel\Image\ImagePipeline;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use TypeError;

class ImageManagerTest extends TestCase
{
    public function testDefaultDriverReturnsConfiguredValue(): void
    {
        $app = $this->makeApp(['images.default' => 'imagick']);

        $manager = new ImageManager($app);

        $this->assertSame('imagick', $manager->getDefaultDriver());
    }

    public function testExtendRegistersCustomDriver(): void
    {
        $app = $this->makeApp(['images.default' => 'custom']);

        $mockDriver = m::mock(Driver::class);

        $manager = new ImageManager($app);
        $manager->extend('custom', function ($app) use ($mockDriver) {
            return $mockDriver;
        });

        $this->assertSame($mockDriver, $manager->driver('custom'));
    }

    public function testDriverCachesResolvedInstances(): void
    {
        $app = $this->makeApp([]);

        $mockDriver = m::mock(Driver::class);

        $manager = new ImageManager($app);
        $manager->extend('custom', function () use ($mockDriver) {
            return $mockDriver;
        });

        $first = $manager->driver('custom');
        $second = $manager->driver('custom');

        $this->assertSame($first, $second);
    }

    public function testThrowsForUnsupportedDriver(): void
    {
        $app = $this->makeApp([]);

        $manager = new ImageManager($app);

        $this->expectExceptionObject(new InvalidArgumentException('Image driver [nonexistent] is not supported.'));

        $manager->driver('nonexistent');
    }

    public function testCustomDriverExceptionPropagatesUnchanged(): void
    {
        $creatorException = new InvalidArgumentException('Unable to create the custom image driver.');
        $manager = new ImageManager($this->makeApp([]));
        $manager->extend('custom', static function () use ($creatorException): never {
            throw $creatorException;
        });

        try {
            $manager->driver('custom');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($creatorException, $exception);

            return;
        }

        $this->fail('InvalidArgumentException was not thrown.');
    }

    public function testCustomDriverMustReturnADriver(): void
    {
        $manager = new ImageManager($this->makeApp([]));
        $manager->extend('custom', static fn (): object => new class {
        });

        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('must be of type Hypervel\Contracts\Image\Driver');

        $manager->driver('custom');
    }

    public function testFromBytesReturnsImageWithContents(): void
    {
        $app = $this->makeApp([]);
        $manager = new ImageManager($app);

        $contents = $this->fakeImageContents();
        $image = $manager->fromBytes($contents);

        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame($contents, $image->toBytes());
    }

    public function testFromPathReturnsImageFromFilePath(): void
    {
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);
        $path = $file->getRealPath();

        $filesystem = m::mock(Filesystem::class);
        $filesystem->expects('get')
            ->with($path)
            ->andReturn(file_get_contents($path));

        $app = $this->makeApp([]);
        $app->expects('make')
            ->with(Filesystem::class)
            ->andReturn($filesystem);

        $manager = new ImageManager($app);
        $image = $manager->fromPath($path);

        $this->assertInstanceOf(Image::class, $image);
        $this->assertNotEmpty($image->toBytes());
    }

    public function testFromPathIsLazy(): void
    {
        $app = $this->makeApp([]);
        $app->shouldNotReceive('make')->with(Filesystem::class);

        $manager = new ImageManager($app);
        $image = $manager->fromPath('/some/path.jpg');

        $this->assertInstanceOf(Image::class, $image);
    }

    public function testFromStorageReturnsImageFromStorageDiskPath(): void
    {
        $contents = $this->fakeImageContents();

        $disk = m::mock(FilesystemContract::class);
        $disk->expects('get')
            ->with('images/avatar.jpg')
            ->andReturn($contents);

        $filesystem = m::mock(FilesystemFactory::class);
        $filesystem->expects('disk')
            ->with('public')
            ->andReturn($disk);

        $app = $this->makeApp([]);
        $app->expects('make')
            ->with(FilesystemFactory::class)
            ->andReturn($filesystem);

        $manager = new ImageManager($app);
        $image = $manager->fromStorage('images/avatar.jpg', 'public');

        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame($contents, $image->toBytes());
    }

    public function testFromStorageAcceptsBackedEnumDisk(): void
    {
        $contents = $this->fakeImageContents();

        $disk = m::mock(FilesystemContract::class);
        $disk->expects('get')
            ->with('images/avatar.jpg')
            ->andReturn($contents);

        $filesystem = m::mock(FilesystemFactory::class);
        $filesystem->expects('disk')
            ->with(ImageDiskStub::Public)
            ->andReturn($disk);

        $app = $this->makeApp([]);
        $app->expects('make')
            ->with(FilesystemFactory::class)
            ->andReturn($filesystem);

        $manager = new ImageManager($app);
        $image = $manager->fromStorage('images/avatar.jpg', ImageDiskStub::Public);

        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame($contents, $image->toBytes());
    }

    public function testFromStorageAcceptsUnitEnumDisk(): void
    {
        $contents = $this->fakeImageContents();

        $disk = m::mock(FilesystemContract::class);
        $disk->expects('get')
            ->with('images/avatar.jpg')
            ->andReturn($contents);

        $filesystem = m::mock(FilesystemFactory::class);
        $filesystem->expects('disk')
            ->with(ImageUnitDiskStub::public)
            ->andReturn($disk);

        $app = $this->makeApp([]);
        $app->expects('make')
            ->with(FilesystemFactory::class)
            ->andReturn($filesystem);

        $manager = new ImageManager($app);
        $image = $manager->fromStorage('images/avatar.jpg', ImageUnitDiskStub::public);

        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame($contents, $image->toBytes());
    }

    public function testFromStorageReportsAMissingPath(): void
    {
        $disk = m::mock(FilesystemContract::class);
        $disk->expects('get')->with('images/missing.jpg')->andReturnNull();

        $filesystem = m::mock(FilesystemFactory::class);
        $filesystem->expects('disk')->with('public')->andReturn($disk);

        $app = $this->makeApp([]);
        $app->expects('make')->with(FilesystemFactory::class)->andReturn($filesystem);

        $manager = new ImageManager($app);
        $image = $manager->fromStorage('images/missing.jpg', 'public');

        $this->expectException(ImageException::class);
        $this->expectExceptionMessage('Unable to read image from path [images/missing.jpg].');

        $image->toBytes();
    }

    public function testFromStorageIsLazy(): void
    {
        $app = $this->makeApp([]);
        $app->shouldNotReceive('make')->with(FilesystemFactory::class);

        $manager = new ImageManager($app);
        $image = $manager->fromStorage('images/avatar.jpg', 'public');

        $this->assertInstanceOf(Image::class, $image);
    }

    public function testFromStreamReturnsImage(): void
    {
        $contents = $this->fakeImageContents();
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $app = $this->makeApp([]);
        $manager = new ImageManager($app);
        $image = $manager->fromStream($stream);

        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame($contents, $image->toBytes());

        fclose($stream);
    }

    public function testFromStreamIsLazy(): void
    {
        $contents = $this->fakeImageContents();
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $app = $this->makeApp([]);
        $manager = new ImageManager($app);
        $image = $manager->fromStream($stream);

        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame(0, ftell($stream));

        fclose($stream);
    }

    public function testFromStreamThrowsForInvalidData(): void
    {
        $stream = fopen('php://memory', 'r+');

        $app = $this->makeApp([]);
        $manager = new ImageManager($app);

        $this->expectExceptionObject(new ImageException('Invalid stream image data.'));

        try {
            $manager->fromStream($stream)->toBytes();
        } finally {
            fclose($stream);
        }
    }

    public function testFromStreamAcceptsTheNonEmptyStringZero(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, '0');
        rewind($stream);

        try {
            $manager = new ImageManager($this->makeApp([]));

            $this->assertSame('0', $manager->fromStream($stream)->toBytes());
        } finally {
            fclose($stream);
        }
    }

    public function testFromUploadReturnsImageFromUploadedFile(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg', 100, 100);

        $app = $this->makeApp([]);
        $manager = new ImageManager($app);
        $image = $manager->fromUpload($file);

        $this->assertInstanceOf(Image::class, $image);
        $this->assertStringEqualsFile($file->getRealPath(), $image->toBytes());
        $this->assertSame($file, $image->file());
    }

    public function testFromUrlReturnsImage(): void
    {
        $contents = $this->fakeImageContents();

        $http = m::mock(HttpFactory::class);
        $response = m::mock(ClientResponse::class);
        $response->expects('throw')->once()->andReturnSelf();
        $response->expects('body')->andReturn($contents);
        $http->expects('get')->with('https://example.com/photo.jpg')->andReturn($response);

        $app = $this->makeApp([]);
        $app->expects('make')
            ->with(HttpFactory::class)
            ->andReturn($http);

        $manager = new ImageManager($app);
        $image = $manager->fromUrl('https://example.com/photo.jpg');

        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame($contents, $image->toBytes());
    }

    public function testFromUrlIsLazy(): void
    {
        $app = $this->makeApp([]);
        $app->shouldNotReceive('make')->with(HttpFactory::class);

        $manager = new ImageManager($app);
        $image = $manager->fromUrl('https://example.com/photo.jpg');

        $this->assertInstanceOf(Image::class, $image);
    }

    public function testFromUrlResolvesOnceAcrossSequentialVariants(): void
    {
        $http = m::mock(HttpFactory::class);
        $response = m::mock(ClientResponse::class);
        $response->expects('throw')->once()->andReturnSelf();
        $response->expects('body')->once()->andReturn('shared image');
        $http->expects('get')->once()->with('https://example.com/photo.jpg')->andReturn($response);

        $app = $this->makeApp([]);
        $app->expects('make')->once()->with(HttpFactory::class)->andReturn($http);

        $manager = new ImageManager($app);
        $image = $manager->fromUrl('https://example.com/photo.jpg');
        $first = $image->using('first');
        $second = $image->using('second');

        $this->assertSame('shared image', $first->toBytes());
        $this->assertSame('shared image', $second->toBytes());
    }

    #[DataProvider('httpErrorStatusProvider')]
    public function testFromUrlRejectsClientAndServerErrors(int $status): void
    {
        $response = new ClientResponse(new Psr7Response($status, [], '<html>Request Failed</html>'));

        $http = m::mock(HttpFactory::class);
        $http->expects('get')->once()->with('https://example.com/missing.jpg')->andReturn($response);

        $app = $this->makeApp([]);
        $app->expects('make')->once()->with(HttpFactory::class)->andReturn($http);

        $image = (new ImageManager($app))->fromUrl('https://example.com/missing.jpg');

        $this->expectException(RequestException::class);
        $this->expectExceptionCode($status);

        $image->toBytes();
    }

    public static function httpErrorStatusProvider(): array
    {
        return [[404], [500]];
    }

    public function testFromBase64ReturnsImage(): void
    {
        $contents = $this->fakeImageContents();
        $base64 = base64_encode($contents);

        $app = $this->makeApp([]);
        $manager = new ImageManager($app);

        $image = $manager->fromBase64($base64);

        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame($contents, $image->toBytes());
    }

    public function testFromBase64ThrowsForInvalidData(): void
    {
        $app = $this->makeApp([]);
        $manager = new ImageManager($app);

        $this->expectExceptionObject(new ImageException('Invalid base64 image data.'));

        $manager->fromBase64('!!!not-base64!!!')->toBytes();
    }

    public function testFromBase64ThrowsForEmptyData(): void
    {
        $manager = new ImageManager($this->makeApp([]));

        $this->expectExceptionObject(new ImageException('Invalid base64 image data.'));

        $manager->fromBase64('')->toBytes();
    }

    public function testFromBase64AcceptsTheNonEmptyStringZero(): void
    {
        $manager = new ImageManager($this->makeApp([]));

        $this->assertSame('0', $manager->fromBase64(base64_encode('0'))->toBytes());
    }

    public function testExtendOverwritesPreviousRegistration(): void
    {
        $app = $this->makeApp([]);

        $firstDriver = m::mock(Driver::class);
        $secondDriver = m::mock(Driver::class);

        $manager = new ImageManager($app);
        $manager->extend('custom', fn () => $firstDriver);
        $manager->extend('custom', fn () => $secondDriver);

        $this->assertSame($secondDriver, $manager->driver('custom'));
    }

    public function testDriverCachesSeparatelyByName(): void
    {
        $app = $this->makeApp([]);

        $driver1 = m::mock(Driver::class);
        $driver2 = m::mock(Driver::class);

        $manager = new ImageManager($app);
        $manager->extend('one', fn () => $driver1);
        $manager->extend('two', fn () => $driver2);

        $this->assertSame($driver1, $manager->driver('one'));
        $this->assertSame($driver2, $manager->driver('two'));
        $this->assertNotSame($manager->driver('one'), $manager->driver('two'));
    }

    public function testCustomBackendProcessesWithoutInterventionInheritance(): void
    {
        $container = new Container;
        $container->instance('config', new Repository(['images.default' => 'custom']));
        $driver = m::mock(Driver::class);
        $driver->expects('process')
            ->with(
                'source image',
                m::on(static fn (ImagePipeline $pipeline): bool => $pipeline->output->format === 'png'),
            )
            ->andReturn('custom image');

        $manager = new ImageManager($container);
        $manager->extend('custom', static fn (): Driver => $driver);
        $container->instance('image', $manager);
        Container::setInstance($container);

        try {
            $this->assertSame('custom image', $manager->fromBytes('source image')->toPng()->toBytes());
            $this->assertNotInstanceOf(InterventionDriver::class, $manager->driver());
        } finally {
            Container::setInstance(null);
        }
    }

    public function testTransformUsingAppliesHandlersToNewDriverInstances(): void
    {
        $app = $this->makeApp([]);
        $driver = new class implements Driver {
            public array $handlers = [];

            public function process(string $contents, ImagePipeline $pipeline): string
            {
                return $contents;
            }

            public function dominantColor(string $contents): string
            {
                return '#000000';
            }

            public function dimensions(string $contents): array
            {
                return [0, 0];
            }

            public function transformUsing(string $transformation, callable $callback): static
            {
                $this->handlers[$transformation] = $callback;

                return $this;
            }
        };
        $transformation = new readonly class implements Transformation {
        };
        $callback = fn () => null;

        $manager = new ImageManager($app);
        $manager->extend('custom', fn () => $driver);
        $manager->transformUsing('custom', $transformation::class, $callback);

        $this->assertSame($callback, $manager->driver('custom')->handlers[$transformation::class]);
    }

    public function testTransformUsingAppliesHandlersToResolvedDriverInstances(): void
    {
        $app = $this->makeApp([]);
        $driver = new class implements Driver {
            public array $handlers = [];

            public function process(string $contents, ImagePipeline $pipeline): string
            {
                return $contents;
            }

            public function dominantColor(string $contents): string
            {
                return '#000000';
            }

            public function dimensions(string $contents): array
            {
                return [0, 0];
            }

            public function transformUsing(string $transformation, callable $callback): static
            {
                $this->handlers[$transformation] = $callback;

                return $this;
            }
        };
        $transformation = new readonly class implements Transformation {
        };
        $callback = fn () => null;

        $manager = new ImageManager($app);
        $manager->extend('custom', fn () => $driver);
        $manager->driver('custom');
        $manager->transformUsing('custom', $transformation::class, $callback);

        $this->assertSame($callback, $driver->handlers[$transformation::class]);
    }

    protected function fakeImageContents(): string
    {
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);

        return file_get_contents($file->getRealPath());
    }

    protected function makeApp(array $config): Application
    {
        $app = m::mock(Application::class);

        $configRepo = new Repository($config);

        $app->shouldReceive('make')->with('config')->andReturn($configRepo)->byDefault();

        return $app;
    }
}

enum ImageDiskStub: string
{
    case Public = 'public';
}

enum ImageUnitDiskStub
{
    case public;
}
