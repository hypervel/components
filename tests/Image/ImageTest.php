<?php

declare(strict_types=1);

namespace Hypervel\Tests\Image;

use finfo;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Filesystem\Factory as FilesystemFactory;
use Hypervel\Contracts\Filesystem\Filesystem as FilesystemContract;
use Hypervel\Contracts\Image\Driver;
use Hypervel\Contracts\Image\Transformation;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Http\UploadedFile;
use Hypervel\Image\Image;
use Hypervel\Image\ImageException;
use Hypervel\Image\ImageManager;
use Hypervel\Image\ImageOutputOptions;
use Hypervel\Image\ImagePipeline;
use Hypervel\Image\Transformations\Blur;
use Hypervel\Image\Transformations\Contain;
use Hypervel\Image\Transformations\Cover;
use Hypervel\Image\Transformations\Crop;
use Hypervel\Image\Transformations\FlipHorizontally;
use Hypervel\Image\Transformations\FlipVertically;
use Hypervel\Image\Transformations\Grayscale;
use Hypervel\Image\Transformations\Orient;
use Hypervel\Image\Transformations\Resize;
use Hypervel\Image\Transformations\Rotate;
use Hypervel\Image\Transformations\Scale;
use Hypervel\Image\Transformations\Sharpen;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Stringable;
use TypeError;

class ImageTest extends TestCase
{
    public function testCoverReturnsNewInstance(): void
    {
        $image = $this->makeImage();
        $result = $image->cover(100, 200);

        $this->assertNotSame($image, $result);
    }

    public function testScaleReturnsNewInstance(): void
    {
        $image = $this->makeImage();
        $result = $image->scale(800, 600);

        $this->assertNotSame($image, $result);
    }

    public function testContainReturnsNewInstance(): void
    {
        $image = $this->makeImage();
        $result = $image->contain(800, 600);

        $this->assertNotSame($image, $result);
    }

    public function testCropReturnsNewInstance(): void
    {
        $image = $this->makeImage();
        $result = $image->crop(100, 100);

        $this->assertNotSame($image, $result);
    }

    public function testResizeReturnsNewInstance(): void
    {
        $image = $this->makeImage();
        $result = $image->resize(800, 600);

        $this->assertNotSame($image, $result);
    }

    public function testRotateReturnsNewInstance(): void
    {
        $image = $this->makeImage();
        $result = $image->rotate(90);

        $this->assertNotSame($image, $result);
    }

    public function testOrientReturnsNewInstance(): void
    {
        $image = $this->makeImage();
        $result = $image->orient();

        $this->assertNotSame($image, $result);
    }

    public function testBlurReturnsNewInstance(): void
    {
        $image = $this->makeImage();
        $result = $image->blur(10);

        $this->assertNotSame($image, $result);
    }

    public function testGrayscaleReturnsNewInstance(): void
    {
        $image = $this->makeImage();
        $result = $image->grayscale();

        $this->assertNotSame($image, $result);
    }

    public function testOptimizeReturnsNewInstance(): void
    {
        $image = $this->makeImage();
        $result = $image->optimize('webp');

        $this->assertNotSame($image, $result);
    }

    public function testQualityReturnsNewInstance(): void
    {
        $image = $this->makeImage();
        $result = $image->quality(80);

        $this->assertNotSame($image, $result);
    }

    public function testToWebpReturnsNewInstance(): void
    {
        $image = $this->makeImage();

        $this->assertNotSame($image, $image->toWebp());
    }

    public function testToJpgReturnsNewInstance(): void
    {
        $image = $this->makeImage();

        $this->assertNotSame($image, $image->toJpg());
    }

    public function testToPngReturnsNewInstance(): void
    {
        $image = $this->makeImage();

        $this->assertNotSame($image, $image->toPng());
    }

    public function testToGifReturnsNewInstance(): void
    {
        $image = $this->makeImage();

        $this->assertNotSame($image, $image->toGif());
    }

    public function testToAvifReturnsNewInstance(): void
    {
        $image = $this->makeImage();

        $this->assertNotSame($image, $image->toAvif());
    }

    public function testToHeicReturnsNewInstance(): void
    {
        $image = $this->makeImage();

        $this->assertNotSame($image, $image->toHeic());
    }

    public function testToBmpReturnsNewInstance(): void
    {
        $image = $this->makeImage();

        $this->assertNotSame($image, $image->toBmp());
    }

    public function testUsingReturnsNewInstance(): void
    {
        $image = $this->makeImage();
        $result = $image->using('imagick');

        $this->assertNotSame($image, $result);
    }

    public function testOriginalIsNotMutated(): void
    {
        $image = $this->makeImage();
        $originalOptions = clone $this->getOptions($image);

        $image->cover(100, 100)->optimize('webp');

        $this->assertEquals($originalOptions, $this->getOptions($image));
    }

    public function testChainedOperationsAccumulate(): void
    {
        $image = $this->makeImage();
        $result = $image->cover(100, 100)->optimize('webp', 90)->blur(5);

        $options = $this->getOptions($result);

        $this->assertSame(100, $options->coverWidth);
        $this->assertSame(100, $options->coverHeight);
        $this->assertSame('webp', $options->format);
        $this->assertSame(90, $options->quality);
        $this->assertSame(5, $options->blur);
    }

    public function testVariantsFromSameSourceAreIndependent(): void
    {
        $image = $this->makeImage();

        $thumb = $image->cover(100, 100);
        $large = $image->scale(800, 600);

        $thumbOptions = $this->getOptions($thumb);
        $largeOptions = $this->getOptions($large);

        $this->assertSame(100, $thumbOptions->coverWidth);
        $this->assertNull($thumbOptions->scaleWidth);

        $this->assertNull($largeOptions->coverWidth);
        $this->assertSame(800, $largeOptions->scaleWidth);
    }

    public function testToBytesReturnsString(): void
    {
        $contents = $this->fakeImageContents();
        $image = new Image($contents);

        $this->assertSame($contents, $image->toBytes());
    }

    public function testToBytesWithClosure(): void
    {
        $contents = $this->fakeImageContents();
        $image = new Image(fn () => $contents);

        $this->assertSame($contents, $image->toBytes());
    }

    public function testClosureIsNotCalledUntilToBytes(): void
    {
        $called = false;

        $image = new Image(function () use (&$called) {
            $called = true;

            return $this->fakeImageContents();
        });

        $this->assertFalse($called);

        $image->toBytes();

        $this->assertTrue($called);
    }

    public function testClosureIsOnlyCalledOnceForRepeatedRawBytes(): void
    {
        $calls = 0;
        $image = new Image(function () use (&$calls): string {
            ++$calls;

            return 'source image';
        });

        $this->assertSame('source image', $image->toBytes());
        $this->assertSame('source image', $image->toBytes());
        $this->assertSame(1, $calls);
    }

    public function testClosureMustReturnString(): void
    {
        $image = new Image(fn (): int => 123);

        $this->expectExceptionObject(new ImageException(
            'Image source resolver must return a string, int returned.',
        ));

        $image->toBytes();
    }

    public function testMimeTypeDetectsJpeg(): void
    {
        $image = new Image($this->fakeImageContents());

        $this->assertSame('image/jpeg', $image->mimeType());
    }

    public function testExtensionReturnsJpgForJpeg(): void
    {
        $image = new Image($this->fakeImageContents());

        $this->assertSame('jpg', $image->extension());
    }

    public function testExtensionReturnsAvifForAvif(): void
    {
        $contents = file_get_contents(__DIR__ . '/Fixtures/image.avif');

        // Validate the fixture independently of the installed magic database.
        $this->assertSame('ftypavif', substr($contents, 4, 8));
        $this->assertNotFalse(getimagesizefromstring($contents), 'The AVIF fixture is truncated or corrupt.');

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        if ($mimeType !== 'image/avif') {
            $this->markTestSkipped('The installed fileinfo database does not recognize AVIF.');
        }

        $image = new Image($contents);

        $this->assertSame('avif', $image->extension());
    }

    public function testDimensionsReturnsWidthAndHeight(): void
    {
        $image = new Image($this->fakeImageContents(300, 200));

        $this->assertSame([300, 200], $image->dimensions());
    }

    public function testDimensionsThrowsWhenDimensionsCannotBeDetermined(): void
    {
        $image = new Image('not-an-image');

        $this->expectExceptionObject(new ImageException('Unable to determine the dimensions of the image.'));

        $image->dimensions();
    }

    public function testDimensionsDefersToTheDriverForHeicImages(): void
    {
        $container = new Container;
        $container->instance('config', new Repository(['images' => ['default' => 'fake']]));

        $manager = new ImageManager($container);
        $manager->extend('fake', fn () => new class implements Driver {
            public function process(string $contents, ImagePipeline $pipeline): string
            {
                // Bytes that finfo reports as image/heic but getimagesizefromstring() cannot read.
                return "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic";
            }

            public function dimensions(string $contents): array
            {
                return [123, 45];
            }

            public function dominantColor(string $contents): string
            {
                return '#000000';
            }

            public function transformUsing(string $transformation, callable $callback): static
            {
                return $this;
            }
        });
        $container->instance('image', $manager);

        Container::setInstance($container);

        try {
            $image = (new Image($this->fakeImageContents()))->using('fake')->cover(1, 1);

            $this->assertSame([123, 45], $image->dimensions());
            $this->assertSame(123, $image->width());
            $this->assertSame(45, $image->height());
        } finally {
            Container::setInstance(null);
        }
    }

    public function testDimensionsPreservesDriverFailureForHeic(): void
    {
        $driverException = new ImageException('The driver cannot decode this image.');
        $driver = m::mock(Driver::class);
        $driver->expects('process')
            ->once()
            ->andReturn("\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic");
        $driver->expects('dimensions')->once()->andThrow($driverException);
        $this->registerDrivers(['fake' => $driver]);

        try {
            (new Image('source image'))->using('fake')->blur()->dimensions();
        } catch (ImageException $exception) {
            $this->assertSame(
                'Unable to determine the dimensions of the image: The driver cannot decode this image.',
                $exception->getMessage(),
            );
            $this->assertSame($driverException, $exception->getPrevious());

            return;
        }

        $this->fail('ImageException was not thrown.');
    }

    public function testDimensionsDoesNotRelabelUnsupportedDriverForHeic(): void
    {
        $this->registerDrivers([]);

        $this->expectExceptionObject(new InvalidArgumentException('Image driver [missing] is not supported.'));

        (new Image("\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic"))
            ->using('missing')
            ->dimensions();
    }

    public function testDimensionsDoesNotMaskDriverTypeErrorsForHeic(): void
    {
        $contents = "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic";
        $driver = m::mock(Driver::class);
        $driver->expects('process')->once()->andReturn($contents);
        $driver->expects('dimensions')->once()->andThrow(new TypeError('broken dimensions'));
        $this->registerDrivers(['fake' => $driver]);

        $this->expectExceptionObject(new TypeError('broken dimensions'));

        (new Image('source image'))->using('fake')->blur()->dimensions();
    }

    public function testDominantColorReusesProcessedBytes(): void
    {
        $driver = m::mock(Driver::class);
        $driver->expects('process')
            ->once()
            ->with('source image', m::type(ImagePipeline::class))
            ->andReturn('processed image');
        $driver->expects('dominantColor')
            ->once()
            ->with('processed image')
            ->andReturn('#123456');
        $this->registerDrivers(['fake' => $driver]);

        $image = (new Image('source image'))->using('fake')->blur();

        $this->assertSame('#123456', $image->dominantColor());
        $this->assertSame('processed image', $image->toBytes());
        $this->assertSame('#123456', $image->dominantColor());
    }

    public function testStorePassesOptionsToTheFilesystem(): void
    {
        $contents = $this->fakeImageContents();
        $image = new Image($contents);
        $path = $image->hashName('images');

        $this->expectImageStored('photos', $path, $contents, ['visibility' => 'private']);

        $this->assertSame($path, $image->store('images', 'photos', ['visibility' => 'private']));
    }

    public function testStoreReturnsFalseWhenTheWriteFails(): void
    {
        $contents = $this->fakeImageContents();
        $image = new Image($contents);
        $path = $image->hashName('images');

        $this->expectImageStored('photos', $path, $contents, [], result: false);

        $this->assertFalse($image->store('images', 'photos'));
    }

    public function testStorePubliclyForcesPublicVisibility(): void
    {
        $contents = $this->fakeImageContents();
        $image = new Image($contents);
        $path = $image->hashName('images');

        $this->expectImageStored('photos', $path, $contents, ['visibility' => 'public']);

        $this->assertSame($path, $image->storePublicly('images', 'photos', ['visibility' => 'private']));
    }

    public function testStoreAsPassesOptionsToTheFilesystem(): void
    {
        $contents = $this->fakeImageContents();
        $image = new Image($contents);

        $this->expectImageStored('photos', 'images/avatar.jpg', $contents, ['visibility' => 'private']);

        $this->assertSame(
            'images/avatar.jpg',
            $image->storeAs('images', 'avatar.jpg', 'photos', ['visibility' => 'private']),
        );
    }

    public function testStorePubliclyAsForcesPublicVisibility(): void
    {
        $contents = $this->fakeImageContents();
        $image = new Image($contents);

        $this->expectImageStored('photos', 'images/avatar.jpg', $contents, ['visibility' => 'public']);

        $this->assertSame(
            'images/avatar.jpg',
            $image->storePubliclyAs('images', 'avatar.jpg', 'photos', ['visibility' => 'private']),
        );
    }

    public function testHashNameReturnsNameWithExtension(): void
    {
        $image = new Image($this->fakeImageContents());

        $name = $image->hashName();

        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{40}\.jpg$/', $name);
    }

    public function testHashNameWithPath(): void
    {
        $image = new Image($this->fakeImageContents());

        $name = $image->hashName('avatars');

        $this->assertStringStartsWith('avatars/', $name);
        $this->assertMatchesRegularExpression('/^avatars\/[a-zA-Z0-9]{40}\.jpg$/', $name);
    }

    public function testFileReturnsUploadedFileWhenProvided(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg');
        $image = new Image(fn () => $file->getContent(), $file);

        $this->assertSame($file, $image->file());
    }

    public function testFileReturnsNullWhenNotProvided(): void
    {
        $image = new Image($this->fakeImageContents());

        $this->assertNull($image->file());
    }

    public function testClonePreservesUploadedFile(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg');
        $image = new Image(fn () => $file->getContent(), $file);

        $cloned = $image->cover(100, 100);

        $this->assertSame($file, $cloned->file());
    }

    public function testOptimizeHasDefaults(): void
    {
        $image = $this->makeImage();
        $result = $image->optimize();

        $options = $this->getOptions($result);

        $this->assertSame('webp', $options->format);
        $this->assertSame(70, $options->quality);
    }

    public function testOptimizeThrowsForUnsupportedFormat(): void
    {
        $image = $this->makeImage();

        $this->expectExceptionObject(new ImageException('The [tiff] format is not supported.'));

        $image->optimize('tiff');
    }

    public function testQualitySetsOption(): void
    {
        $image = $this->makeImage();
        $result = $image->quality(60);

        $this->assertSame(60, $this->getOptions($result)->quality);
    }

    public function testEffectAndQualityBoundaryValuesAreAccepted(): void
    {
        $image = $this->makeImage();

        $this->assertSame(0, $this->getOptions($image->blur(0))->blur);
        $this->assertSame(100, $this->getOptions($image->blur(100))->blur);
        $this->assertSame(0, $this->getOptions($image->sharpen(0))->sharpen);
        $this->assertSame(100, $this->getOptions($image->sharpen(100))->sharpen);
        $this->assertSame(1, $this->getOptions($image->quality(1))->quality);
        $this->assertSame(100, $this->getOptions($image->quality(100))->quality);
    }

    #[DataProvider('invalidEffectAndQualityProvider')]
    public function testEffectAndQualityValuesOutsideTheirRangesAreRejected(
        string $method,
        array $arguments,
        string $message,
    ): void {
        $this->expectExceptionObject(new ImageException($message));

        $this->makeImage()->{$method}(...$arguments);
    }

    /**
     * Provide invalid effect and quality values.
     *
     * @return array<string, array{string, array<int, int|string>, string}>
     */
    public static function invalidEffectAndQualityProvider(): array
    {
        return [
            'blur below minimum' => ['blur', [-1], 'Image blur amount must be between 0 and 100.'],
            'blur above maximum' => ['blur', [101], 'Image blur amount must be between 0 and 100.'],
            'sharpen below minimum' => ['sharpen', [-1], 'Image sharpen amount must be between 0 and 100.'],
            'sharpen above maximum' => ['sharpen', [101], 'Image sharpen amount must be between 0 and 100.'],
            'quality below minimum' => ['quality', [0], 'Image quality must be between 1 and 100.'],
            'quality above maximum' => ['quality', [101], 'Image quality must be between 1 and 100.'],
            'optimize quality below minimum' => ['optimize', ['webp', 0], 'Image quality must be between 1 and 100.'],
            'optimize quality above maximum' => ['optimize', ['webp', 101], 'Image quality must be between 1 and 100.'],
        ];
    }

    public function testToWebpSetsFormat(): void
    {
        $image = $this->makeImage();

        $this->assertSame('webp', $this->getOptions($image->toWebp())->format);
    }

    public function testToJpgSetsFormat(): void
    {
        $image = $this->makeImage();

        $this->assertSame('jpg', $this->getOptions($image->toJpg())->format);
    }

    public function testToJpegIsAliasForToJpg(): void
    {
        $image = $this->makeImage();

        $this->assertSame('jpg', $this->getOptions($image->toJpeg())->format);
    }

    public function testToPngSetsFormat(): void
    {
        $image = $this->makeImage();

        $this->assertSame('png', $this->getOptions($image->toPng())->format);
    }

    public function testToGifSetsFormat(): void
    {
        $image = $this->makeImage();

        $this->assertSame('gif', $this->getOptions($image->toGif())->format);
    }

    public function testToAvifSetsFormat(): void
    {
        $image = $this->makeImage();

        $this->assertSame('avif', $this->getOptions($image->toAvif())->format);
    }

    public function testToHeicSetsFormat(): void
    {
        $image = $this->makeImage();

        $this->assertSame('heic', $this->getOptions($image->toHeic())->format);
    }

    public function testToBmpSetsFormat(): void
    {
        $image = $this->makeImage();

        $this->assertSame('bmp', $this->getOptions($image->toBmp())->format);
    }

    public function testToFormatSupportsEveryPublicFormatSpelling(): void
    {
        $image = $this->makeImage();

        foreach ([
            'webp' => 'webp',
            'jpg' => 'jpg',
            'jpeg' => 'jpeg',
            'png' => 'png',
            'gif' => 'gif',
            'avif' => 'avif',
            'heic' => 'heic',
            'heif' => 'heic',
            'bmp' => 'bmp',
        ] as $format => $expected) {
            $this->assertSame($expected, $this->getOptions($image->toFormat($format))->format);
        }
    }

    public function testToFormatRejectsUnsupportedFormat(): void
    {
        $this->expectExceptionObject(new ImageException('The [WEBP] format is not supported.'));

        $this->makeImage()->toFormat('WEBP');
    }

    public function testQualitySurvivesFormatConversion(): void
    {
        $image = $this->makeImage();

        $this->assertSame(50, $this->getOptions($image->quality(50)->toJpg())->quality);
        $this->assertSame(90, $this->getOptions($image->quality(90)->toWebp())->quality);
    }

    public function testFormatAndQualityCanBeSetSeparately(): void
    {
        $image = $this->makeImage();
        $result = $image->toWebp()->quality(60);

        $options = $this->getOptions($result);

        $this->assertSame('webp', $options->format);
        $this->assertSame(60, $options->quality);
    }

    public function testBlurHasDefault(): void
    {
        $image = $this->makeImage();
        $result = $image->blur();

        $this->assertSame(5, $this->getOptions($result)->blur);
    }

    public function testSharpenReturnsNewInstance(): void
    {
        $image = $this->makeImage();

        $this->assertNotSame($image, $image->sharpen());
    }

    public function testSharpenSetsOption(): void
    {
        $image = $this->makeImage();

        $this->assertSame(20, $this->getOptions($image->sharpen(20))->sharpen);
    }

    public function testSharpenHasDefault(): void
    {
        $image = $this->makeImage();

        $this->assertSame(10, $this->getOptions($image->sharpen())->sharpen);
    }

    public function testFlipVerticallyReturnsNewInstance(): void
    {
        $image = $this->makeImage();

        $this->assertNotSame($image, $image->flipVertically());
    }

    public function testFlipVerticallySetsOption(): void
    {
        $image = $this->makeImage();

        $this->assertTrue($this->getOptions($image->flipVertically())->flipVertically);
    }

    public function testFlipHorizontallyReturnsNewInstance(): void
    {
        $image = $this->makeImage();

        $this->assertNotSame($image, $image->flipHorizontally());
    }

    public function testFlipHorizontallySetsOption(): void
    {
        $image = $this->makeImage();

        $this->assertTrue($this->getOptions($image->flipHorizontally())->flipHorizontally);
    }

    public function testWidthReturnsInt(): void
    {
        $image = new Image($this->fakeImageContents(300, 200));

        $this->assertSame(300, $image->width());
    }

    public function testHeightReturnsInt(): void
    {
        $image = new Image($this->fakeImageContents(300, 200));

        $this->assertSame(200, $image->height());
    }

    public function testToBase64ReturnsEncodedString(): void
    {
        $contents = $this->fakeImageContents();
        $image = new Image($contents);

        $this->assertSame(base64_encode($contents), $image->toBase64());
    }

    public function testToDataUriReturnsDataUri(): void
    {
        $image = new Image($this->fakeImageContents());

        $dataUri = $image->toDataUri();

        $this->assertStringStartsWith('data:image/jpeg;base64,', $dataUri);
    }

    public function testDriverExceptionIsWrappedInImageException(): void
    {
        $image = new Image($this->fakeImageContents());

        $this->expectExceptionObject(new ImageException('Failed to process image:'));

        // Trigger a driver error by using a non-existent driver
        $image->using('nonexistent')->cover(100, 100)->toBytes();
    }

    public function testWrappedExceptionPreservesOriginal(): void
    {
        $image = new Image($this->fakeImageContents());

        try {
            $image->using('nonexistent')->cover(100, 100)->toBytes();
        } catch (ImageException $exception) {
            $this->assertNotNull($exception->getPrevious());

            return;
        }

        $this->fail('ImageException was not thrown.');
    }

    public function testProcessingDoesNotMaskDriverTypeErrors(): void
    {
        $driver = m::mock(Driver::class);
        $driver->expects('process')->once()->andThrow(new TypeError('broken process'));
        $this->registerDrivers(['fake' => $driver]);

        $this->expectExceptionObject(new TypeError('broken process'));

        (new Image('source image'))->using('fake')->blur()->toBytes();
    }

    public function testToBytesReturnsSameResultOnMultipleCalls(): void
    {
        $image = new Image($this->fakeImageContents());

        $first = $image->toBytes();
        $second = $image->toBytes();

        $this->assertSame($first, $second);
    }

    public function testToBytesProcessesARecipeOnlyOnce(): void
    {
        $driver = m::mock(Driver::class);
        $driver->expects('process')
            ->once()
            ->with('source image', m::type(ImagePipeline::class))
            ->andReturn('processed image');
        $this->registerDrivers(['fake' => $driver]);

        $image = (new Image('source image'))->using('fake')->blur();

        $this->assertSame('processed image', $image->toBytes());
        $this->assertSame('processed image', $image->toBytes());
    }

    public function testToBytesWithoutOperationsReturnsOriginal(): void
    {
        $contents = $this->fakeImageContents();
        $image = new Image($contents);

        $this->assertSame($contents, $image->toBytes());
    }

    public function testHasChangesWithOnlyQualitySet(): void
    {
        $image = $this->makeImage();
        $result = $image->quality(50);

        $this->assertTrue($this->getPipeline($result)->hasChanges());
    }

    public function testCloneDoesNotShareHashNameCache(): void
    {
        $image = $this->makeImage();
        $originalName = $image->hashName();

        $clone = $image->usingGd();
        $cloneName = $clone->hashName();

        $this->assertNotSame($originalName, $cloneName);
        $this->assertSame($originalName, $image->hashName());
        $this->assertSame($cloneName, $clone->hashName());
    }

    public function testHashNameIsConsistentOnSameInstance(): void
    {
        $image = $this->makeImage();

        $this->assertSame($image->hashName(), $image->hashName());
    }

    public function testInstanceMetadataIsCachedAndInvalidatedOnClones(): void
    {
        $contents = $this->fakeImageContents(300, 200);
        $driver = m::mock(Driver::class);
        $driver->expects('process')->once()->andReturn($contents);
        $driver->expects('dominantColor')->once()->with($contents)->andReturn('#123456');
        $this->registerDrivers(['fake' => $driver]);

        $image = (new Image('source image'))->using('fake')->blur();

        $this->assertSame('image/jpeg', $image->mimeType());
        $this->assertSame('image/jpeg', $image->mimeType());
        $this->assertSame([300, 200], $image->dimensions());
        $this->assertSame([300, 200], $image->dimensions());
        $this->assertSame('#123456', $image->dominantColor());
        $this->assertSame('#123456', $image->dominantColor());
        $this->assertSame($image->hashName(), $image->hashName());

        $clone = $image->quality(80);

        foreach (['processedContents', 'mimeType', 'dimensions', 'dominantColor', 'hashName'] as $property) {
            $this->assertNull((new ReflectionProperty($clone, $property))->getValue($clone));
        }
    }

    public function testFlipAliasSetsVerticalOption(): void
    {
        $image = $this->makeImage();

        $this->assertTrue($this->getOptions($image->flip())->flipVertically);
    }

    public function testFlopAliasSetsHorizontalOption(): void
    {
        $image = $this->makeImage();

        $this->assertTrue($this->getOptions($image->flop())->flipHorizontally);
    }

    public function testFlipVerticallyAndHorizontallyTogether(): void
    {
        $image = $this->makeImage();
        $result = $image->flipVertically()->flipHorizontally();

        $this->assertTrue($this->getOptions($result)->flipVertically);
        $this->assertTrue($this->getOptions($result)->flipHorizontally);
    }

    public function testMultipleOperationsChained(): void
    {
        $image = $this->makeImage();
        $result = $image->orient()->cover(200, 200)->blur(10)->grayscale()->sharpen(5)->toWebp()->quality(75);

        $options = $this->getOptions($result);

        $this->assertTrue($options->orient);
        $this->assertSame(200, $options->coverWidth);
        $this->assertSame(200, $options->coverHeight);
        $this->assertSame(10, $options->blur);
        $this->assertTrue($options->grayscale);
        $this->assertSame(5, $options->sharpen);
        $this->assertSame('webp', $options->format);
        $this->assertSame(75, $options->quality);
    }

    public function testLaterOperationOverridesEarlier(): void
    {
        $image = $this->makeImage();
        $result = $image->cover(200, 200)->cover(100, 100);

        $options = $this->getOptions($result);

        $this->assertSame(100, $options->coverWidth);
        $this->assertSame(100, $options->coverHeight);
    }

    public function testExtensionReturnsBinForUnknownMime(): void
    {
        $image = new Image('not-an-image');

        $this->assertSame('bin', $image->extension());
    }

    public function testFileReturnsNullForNonUpload(): void
    {
        $image = Image::class;
        $instance = new $image($this->fakeImageContents());

        $this->assertNull($instance->file());
    }

    public function testUsingGdShortcut(): void
    {
        $image = $this->makeImage();
        $result = $image->usingGd();

        $driver = (new ReflectionProperty($result, 'driver'))->getValue($result);

        $this->assertSame('gd', $driver);
    }

    public function testUsingImagickShortcut(): void
    {
        $image = $this->makeImage();
        $result = $image->usingImagick();

        $driver = (new ReflectionProperty($result, 'driver'))->getValue($result);

        $this->assertSame('imagick', $driver);
    }

    public function testDimensionsOnTinyImage(): void
    {
        $image = new Image($this->fakeImageContents(1, 1));

        $this->assertSame([1, 1], $image->dimensions());
        $this->assertSame(1, $image->width());
        $this->assertSame(1, $image->height());
    }

    public function testToDataUriContainsValidBase64(): void
    {
        $image = new Image($this->fakeImageContents());

        $dataUri = $image->toDataUri();
        $base64Part = substr($dataUri, strpos($dataUri, ',') + 1);

        $this->assertNotFalse(base64_decode($base64Part, true));
    }

    public function testOptimizeThrowsForJpgWithWrongSpelling(): void
    {
        $image = $this->makeImage();

        $this->expectExceptionObject(new ImageException('The [jpge] format is not supported.'));

        $image->optimize('jpge');
    }

    public function testOptimizeAllowsPng(): void
    {
        $result = $this->makeImage()->optimize('png');

        $this->assertSame('png', $this->getOptions($result)->format);
    }

    public function testSerializationThrowsException(): void
    {
        $image = new Image($this->fakeImageContents());

        $this->expectExceptionObject(new ImageException('Images cannot be serialized. Store the image first and serialize the path instead.'));

        serialize($image);
    }

    public function testImagePipelineHasNoChangesByDefault(): void
    {
        $pipeline = new ImagePipeline;

        $this->assertFalse($pipeline->hasChanges());
    }

    public function testImagePipelineHasChangesWithZeroQuality(): void
    {
        $pipeline = new ImagePipeline;
        $pipeline->output->quality = 0;

        $this->assertTrue($pipeline->hasChanges());
    }

    public function testImagePipelineHasChangesWithZeroBlur(): void
    {
        $pipeline = new ImagePipeline;
        $pipeline->add(new Blur(0));

        $this->assertTrue($pipeline->hasChanges());
    }

    public function testImagePipelineHasChangesWithZeroSharpen(): void
    {
        $pipeline = new ImagePipeline;
        $pipeline->add(new Sharpen(0));

        $this->assertTrue($pipeline->hasChanges());
    }

    public function testImageOutputOptionsDefaultQualityConstant(): void
    {
        $this->assertSame(70, ImageOutputOptions::DEFAULT_QUALITY);
    }

    public function testCoverSetsBothDimensions(): void
    {
        $image = $this->makeImage();
        $result = $image->cover(300, 150);

        $options = $this->getOptions($result);

        $this->assertSame(300, $options->coverWidth);
        $this->assertSame(150, $options->coverHeight);
    }

    public function testScaleSetsBothDimensions(): void
    {
        $image = $this->makeImage();
        $result = $image->scale(1200, 800);

        $options = $this->getOptions($result);

        $this->assertSame(1200, $options->scaleWidth);
        $this->assertSame(800, $options->scaleHeight);
    }

    public function testContainSetsDimensionsAndBackground(): void
    {
        $image = $this->makeImage();
        $result = $image->contain(1200, 800, '#ffffff');

        $options = $this->getOptions($result);

        $this->assertSame(1200, $options->containWidth);
        $this->assertSame(800, $options->containHeight);
        $this->assertSame('#ffffff', $options->containBackground);
    }

    public function testContainSetsDominantBackground(): void
    {
        $image = $this->makeImage();
        $result = $image->contain(1200, 800, 'dominant');

        $options = $this->getOptions($result);

        $this->assertSame(1200, $options->containWidth);
        $this->assertSame(800, $options->containHeight);
        $this->assertSame('dominant', $options->containBackground);
    }

    public function testCropSetsDimensionsAndPosition(): void
    {
        $image = $this->makeImage();
        $result = $image->crop(300, 200, 10, 20);

        $options = $this->getOptions($result);

        $this->assertSame(300, $options->cropWidth);
        $this->assertSame(200, $options->cropHeight);
        $this->assertSame(10, $options->cropX);
        $this->assertSame(20, $options->cropY);
    }

    public function testCropAcceptsNegativeOffsets(): void
    {
        $options = $this->getOptions($this->makeImage()->crop(300, 200, -2, -3));

        $this->assertSame([300, 200, -2, -3], [
            $options->cropWidth,
            $options->cropHeight,
            $options->cropX,
            $options->cropY,
        ]);
    }

    #[DataProvider('invalidDimensionProvider')]
    public function testDimensionTransformationsRejectNonPositiveDimensions(
        string $method,
        array $arguments,
        string $message,
    ): void {
        $this->expectExceptionObject(new ImageException($message));

        $this->makeImage()->{$method}(...$arguments);
    }

    /**
     * Provide non-positive image dimensions.
     *
     * @return array<string, array{string, array<int, null|int>, string}>
     */
    public static function invalidDimensionProvider(): array
    {
        return [
            'cover zero width' => ['cover', [0, 1], 'Image width must be greater than zero.'],
            'cover zero height' => ['cover', [1, 0], 'Image height must be greater than zero.'],
            'cover negative width' => ['cover', [-1, 1], 'Image width must be greater than zero.'],
            'cover negative height' => ['cover', [1, -1], 'Image height must be greater than zero.'],
            'contain zero width' => ['contain', [0, 1], 'Image width must be greater than zero.'],
            'crop negative height' => ['crop', [1, -1], 'Image height must be greater than zero.'],
            'resize zero width' => ['resize', [0], 'Image width must be greater than zero.'],
            'resize negative height' => ['resize', [null, -1], 'Image height must be greater than zero.'],
            'scale negative width' => ['scale', [-1], 'Image width must be greater than zero.'],
            'scale zero height' => ['scale', [null, 0], 'Image height must be greater than zero.'],
        ];
    }

    public function testResizeSetsBothDimensions(): void
    {
        $image = $this->makeImage();
        $result = $image->resize(1200, 800);

        $options = $this->getOptions($result);

        $this->assertSame(1200, $options->resizeWidth);
        $this->assertSame(800, $options->resizeHeight);
    }

    public function testResizeSetsWidthOnly(): void
    {
        $image = $this->makeImage();
        $result = $image->resize(width: 1200);

        $options = $this->getOptions($result);

        $this->assertSame(1200, $options->resizeWidth);
        $this->assertNull($options->resizeHeight);
    }

    public function testResizeSetsHeightOnly(): void
    {
        $image = $this->makeImage();
        $result = $image->resize(height: 800);

        $options = $this->getOptions($result);

        $this->assertNull($options->resizeWidth);
        $this->assertSame(800, $options->resizeHeight);
    }

    public function testResizeRequiresAtLeastOneDimension(): void
    {
        $this->expectExceptionObject(new ImageException('At least one resize dimension must be specified.'));

        $this->makeImage()->resize();
    }

    public function testRotateSetsAngleAndBackground(): void
    {
        $image = $this->makeImage();
        $result = $image->rotate(90, '#ffffff');

        $options = $this->getOptions($result);

        $this->assertSame(90.0, $options->rotateAngle);
        $this->assertSame('#ffffff', $options->rotateBackground);
    }

    public function testRotateSetsDominantBackground(): void
    {
        $image = $this->makeImage();
        $result = $image->rotate(45, 'dominant');

        $options = $this->getOptions($result);

        $this->assertSame(45.0, $options->rotateAngle);
        $this->assertSame('dominant', $options->rotateBackground);
    }

    public function testScaleSetsWidthOnly(): void
    {
        $image = $this->makeImage();
        $result = $image->scale(width: 1200);

        $options = $this->getOptions($result);

        $this->assertSame(1200, $options->scaleWidth);
        $this->assertNull($options->scaleHeight);
    }

    public function testScaleSetsHeightOnly(): void
    {
        $image = $this->makeImage();
        $result = $image->scale(height: 800);

        $options = $this->getOptions($result);

        $this->assertNull($options->scaleWidth);
        $this->assertSame(800, $options->scaleHeight);
    }

    public function testScaleRequiresAtLeastOneDimension(): void
    {
        $this->expectExceptionObject(new ImageException('At least one scale dimension must be specified.'));

        $this->makeImage()->scale();
    }

    public function testOrientSetsOption(): void
    {
        $image = $this->makeImage();
        $result = $image->orient();

        $this->assertTrue($this->getOptions($result)->orient);
    }

    public function testOptimizeSetsBothFormatAndQuality(): void
    {
        $image = $this->makeImage();
        $result = $image->optimize('jpg', 90);

        $options = $this->getOptions($result);

        $this->assertSame('jpg', $options->format);
        $this->assertSame(90, $options->quality);
    }

    public function testOptimizeAllowsGif(): void
    {
        $result = $this->makeImage()->optimize('gif');

        $this->assertSame('gif', $this->getOptions($result)->format);
    }

    public function testOptimizeAllowsAvif(): void
    {
        $result = $this->makeImage()->optimize('avif');

        $this->assertSame('avif', $this->getOptions($result)->format);
    }

    public function testOptimizeAllowsHeic(): void
    {
        $result = $this->makeImage()->optimize('heic');

        $this->assertSame('heic', $this->getOptions($result)->format);
    }

    public function testOptimizeNormalizesHeifToHeic(): void
    {
        $result = $this->makeImage()->optimize('heif');

        $this->assertSame('heic', $this->getOptions($result)->format);
    }

    public function testOptimizeAllowsJpegSpelling(): void
    {
        $image = $this->makeImage();
        $result = $image->optimize('jpeg', 90);

        $this->assertSame('jpeg', $this->getOptions($result)->format);
    }

    public function testScaleDoesNotSetCover(): void
    {
        $image = $this->makeImage();
        $result = $image->scale(800, 600);

        $options = $this->getOptions($result);

        $this->assertNull($options->coverWidth);
        $this->assertNull($options->coverHeight);
    }

    public function testCoverDoesNotSetScale(): void
    {
        $image = $this->makeImage();
        $result = $image->cover(200, 200);

        $options = $this->getOptions($result);

        $this->assertNull($options->scaleWidth);
        $this->assertNull($options->scaleHeight);
    }

    public function testThreeVariantsFromSameSource(): void
    {
        $image = $this->makeImage();

        $a = $image->cover(100, 100);
        $b = $image->scale(800, 600);
        $c = $image->blur(10);

        $this->assertSame(100, $this->getOptions($a)->coverWidth);
        $this->assertNull($this->getOptions($a)->scaleWidth);
        $this->assertNull($this->getOptions($a)->blur);

        $this->assertNull($this->getOptions($b)->coverWidth);
        $this->assertSame(800, $this->getOptions($b)->scaleWidth);
        $this->assertNull($this->getOptions($b)->blur);

        $this->assertNull($this->getOptions($c)->coverWidth);
        $this->assertNull($this->getOptions($c)->scaleWidth);
        $this->assertSame(10, $this->getOptions($c)->blur);
    }

    public function testMaterializedCloneReprocessesTheOriginalSourceWithTheFullRecipe(): void
    {
        $recipes = [];
        $driver = m::mock(Driver::class);
        $driver->expects('process')
            ->twice()
            ->andReturnUsing(static function (string $contents, ImagePipeline $pipeline) use (&$recipes): string {
                $recipes[] = [
                    $contents,
                    array_map(
                        static fn (Transformation $transformation): string => $transformation::class,
                        $pipeline->transformations,
                    ),
                ];

                return 'processed image ' . count($recipes);
            });
        $this->registerDrivers(['fake' => $driver]);

        $image = (new Image('original image'))->using('fake')->blur(1);

        $this->assertSame('processed image 1', $image->toBytes());

        $clone = $image->sharpen(2);

        $this->assertSame('processed image 2', $clone->toBytes());
        $this->assertSame([
            ['original image', [Blur::class]],
            ['original image', [Blur::class, Sharpen::class]],
        ], $recipes);
    }

    public function testUsingSetsDriverString(): void
    {
        $image = $this->makeImage();
        $result = $image->using('custom-driver');

        $driver = (new ReflectionProperty($result, 'driver'))->getValue($result);

        $this->assertSame('custom-driver', $driver);
    }

    public function testSwitchingDriversReprocessesTheRetainedRecipe(): void
    {
        $firstDriver = m::mock(Driver::class);
        $firstDriver->expects('process')
            ->once()
            ->withArgs(static fn (string $contents, ImagePipeline $pipeline): bool => $contents === 'source image'
                && count($pipeline->transformations) === 1
                && $pipeline->transformations[0] instanceof Blur)
            ->andReturn('first output');

        $secondDriver = m::mock(Driver::class);
        $secondDriver->expects('process')
            ->once()
            ->withArgs(static fn (string $contents, ImagePipeline $pipeline): bool => $contents === 'source image'
                && count($pipeline->transformations) === 1
                && $pipeline->transformations[0] instanceof Blur)
            ->andReturn('second output');

        $this->registerDrivers(['first' => $firstDriver, 'second' => $secondDriver], 'first');

        $image = (new Image('source image'))->using('first')->blur();

        $this->assertSame('first output', $image->toBytes());
        $this->assertSame('second output', $image->using('second')->toBytes());
    }

    public function testImplementsStringable(): void
    {
        $image = new Image($this->fakeImageContents());

        $this->assertInstanceOf(Stringable::class, $image);
    }

    public function testToStringReturnsDataUri(): void
    {
        $image = new Image($this->fakeImageContents());

        $this->assertSame($image->toDataUri(), $image->toString());
    }

    public function testMagicToStringReturnsDataUri(): void
    {
        $image = new Image($this->fakeImageContents());

        $this->assertSame($image->toDataUri(), (string) $image);
    }

    public function testImageExceptionExtendsRuntimeException(): void
    {
        $exception = new ImageException('test');

        $this->assertInstanceOf(RuntimeException::class, $exception);
    }

    public function testImplementsResponsable(): void
    {
        $image = new Image($this->fakeImageContents());

        $this->assertInstanceOf(Responsable::class, $image);
    }

    public function testToResponseReturnsResponseWithImageBytes(): void
    {
        $contents = $this->fakeImageContents();
        $image = new Image($contents);

        $response = $image->toResponse(new Request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame($contents, $response->getContent());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testToResponseSetsContentTypeHeader(): void
    {
        $image = new Image($this->fakeImageContents());

        $response = $image->toResponse(new Request);

        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
    }

    public function testFlushStateRemovesMacros(): void
    {
        Image::macro('temporaryMacro', static fn (): string => 'registered');

        $this->assertTrue(Image::hasMacro('temporaryMacro'));

        Image::flushState();

        $this->assertFalse(Image::hasMacro('temporaryMacro'));
    }

    /**
     * Register image drivers in a concrete global container.
     *
     * @param array<string, Driver> $drivers
     */
    protected function registerDrivers(array $drivers, string $default = 'fake'): void
    {
        $container = new Container;
        $container->instance('config', new Repository(['images' => ['default' => $default]]));

        $manager = new ImageManager($container);

        foreach ($drivers as $name => $driver) {
            $manager->extend($name, static fn (): Driver => $driver);
        }

        $container->instance('image', $manager);
        Container::setInstance($container);
    }

    protected function makeImage(): Image
    {
        return new Image($this->fakeImageContents());
    }

    /**
     * Expect an image to be stored with the given options through a concrete global container.
     *
     * @param array<string, mixed> $options
     */
    protected function expectImageStored(
        string $diskName,
        string $path,
        string $contents,
        array $options,
        bool|string $result = true,
    ): void {
        $filesystem = m::mock(FilesystemContract::class);
        $filesystem->expects('put')
            ->with($path, $contents, $options)
            ->andReturn($result);

        $factory = m::mock(FilesystemFactory::class);
        $factory->expects('disk')
            ->with($diskName)
            ->andReturn($filesystem);

        $container = new Container;
        $container->instance(FilesystemFactory::class, $factory);

        Container::setInstance($container);
    }

    protected function fakeImageContents(int $width = 100, int $height = 100): string
    {
        $file = UploadedFile::fake()->image('test.jpg', $width, $height);

        return file_get_contents($file->getRealPath());
    }

    protected function getOptions(Image $image): object
    {
        $pipeline = (new ReflectionProperty($image, 'pipeline'))->getValue($image);

        $options = (object) [
            'coverWidth' => null,
            'coverHeight' => null,
            'containWidth' => null,
            'containHeight' => null,
            'containBackground' => null,
            'cropWidth' => null,
            'cropHeight' => null,
            'cropX' => null,
            'cropY' => null,
            'resizeWidth' => null,
            'resizeHeight' => null,
            'rotateAngle' => null,
            'rotateBackground' => null,
            'scaleWidth' => null,
            'scaleHeight' => null,
            'orient' => null,
            'blur' => null,
            'grayscale' => null,
            'sharpen' => null,
            'flipVertically' => null,
            'flipHorizontally' => null,
            'format' => $pipeline->output->format,
            'quality' => $pipeline->output->quality,
        ];

        foreach ($pipeline->transformations as $transformation) {
            match (true) {
                $transformation instanceof Cover => [$options->coverWidth, $options->coverHeight] = [$transformation->width, $transformation->height],
                $transformation instanceof Contain => [$options->containWidth, $options->containHeight, $options->containBackground] = [$transformation->width, $transformation->height, $transformation->background],
                $transformation instanceof Crop => [$options->cropWidth, $options->cropHeight, $options->cropX, $options->cropY] = [$transformation->width, $transformation->height, $transformation->x, $transformation->y],
                $transformation instanceof Resize => [$options->resizeWidth, $options->resizeHeight] = [$transformation->width, $transformation->height],
                $transformation instanceof Rotate => [$options->rotateAngle, $options->rotateBackground] = [$transformation->angle, $transformation->background],
                $transformation instanceof Scale => [$options->scaleWidth, $options->scaleHeight] = [$transformation->width, $transformation->height],
                $transformation instanceof Orient => $options->orient = true,
                $transformation instanceof Blur => $options->blur = $transformation->amount,
                $transformation instanceof Grayscale => $options->grayscale = true,
                $transformation instanceof Sharpen => $options->sharpen = $transformation->amount,
                $transformation instanceof FlipVertically => $options->flipVertically = true,
                $transformation instanceof FlipHorizontally => $options->flipHorizontally = true,
                default => null,
            };
        }

        return $options;
    }

    protected function getPipeline(Image $image): ImagePipeline
    {
        return (new ReflectionProperty($image, 'pipeline'))->getValue($image);
    }
}
