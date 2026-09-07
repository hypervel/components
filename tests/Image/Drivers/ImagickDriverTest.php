<?php

declare(strict_types=1);

namespace Hypervel\Tests\Image\Drivers;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Image\Transformation;
use Hypervel\Http\UploadedFile;
use Hypervel\Image\Drivers\ImagickDriver;
use Hypervel\Image\Image;
use Hypervel\Image\ImageException;
use Hypervel\Image\ImageManager;
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
use Imagick;
use ImagickException;
use ImagickPixel;
use Intervention\Image\Interfaces\ImageInterface;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresPhpExtension('imagick')]
class ImagickDriverTest extends TestCase
{
    public function testProcessesCover(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(200, 200);

        $pipeline = $this->pipeline(new Cover(100, 50));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(100, $width);
        $this->assertSame(50, $height);
    }

    public function testProcessesOptimizeToWebp(): void
    {
        $this->ensureImageFormatCanBeEncoded('webp');

        $driver = new ImagickDriver;

        $pipeline = $this->pipeline(format: 'webp');

        $result = $driver->process($this->fakeImageContents(), $pipeline);

        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($result)[2]);
    }

    public function testProcessesOptimizeToJpeg(): void
    {
        $driver = new ImagickDriver;

        $pipeline = $this->pipeline(format: 'jpg');

        $result = $driver->process($this->fakeImageContents(), $pipeline);

        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($result)[2]);
    }

    public function testProcessesOptimizeToPng(): void
    {
        $driver = new ImagickDriver;

        $pipeline = $this->pipeline(format: 'png');

        $result = $driver->process($this->fakeImageContents(), $pipeline);

        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($result)[2]);
    }

    public function testProcessesOptimizeToGif(): void
    {
        $driver = new ImagickDriver;

        $pipeline = $this->pipeline(format: 'gif');

        $result = $driver->process($this->fakeImageContents(), $pipeline);

        $this->assertSame(IMAGETYPE_GIF, getimagesizefromstring($result)[2]);
    }

    public function testProcessesOptimizeToAvif(): void
    {
        $this->ensureImageFormatCanBeEncoded('avif');

        $driver = new ImagickDriver;

        $pipeline = $this->pipeline(format: 'avif');

        $result = $driver->process($this->fakeImageContents(), $pipeline);

        // PHP's getimagesizefromstring()/finfo AVIF detection varies by the
        // libavif/libmagic versions installed, so we assert on the ISOBMFF
        // "ftyp" box and brand directly instead of relying on either.
        $this->assertStringContainsString('ftyp', substr($result, 0, 16));
        $this->assertMatchesRegularExpression('/avif|avis|mif1/', substr($result, 0, 32));
    }

    public function testProcessesAvifInput(): void
    {
        $this->ensureImageFormatCanBeEncoded('avif');

        $driver = new ImagickDriver;
        $contents = $driver->process($this->fakeImageContents(), $this->pipeline(format: 'avif'));

        $result = $driver->process($contents, $this->pipeline(new Cover(50, 25), format: 'jpg'));

        $this->assertSame([50, 25], array_slice(getimagesizefromstring($result), 0, 2));
    }

    public function testProcessesOptimizeToHeic(): void
    {
        $this->ensureImageFormatCanBeEncoded('heic');

        $driver = new ImagickDriver;

        $result = $driver->process($this->fakeImageContents(), $this->pipeline(format: 'heic'));

        $this->assertStringContainsString('ftyp', substr($result, 0, 16));
        $this->assertMatchesRegularExpression('/heic|heix|hevc|hevx|mif1/', substr($result, 0, 32));
    }

    public function testProcessesHeicInput(): void
    {
        $this->ensureImageFormatCanBeEncoded('heic');

        $driver = new ImagickDriver;
        $contents = $driver->process($this->fakeImageContents(), $this->pipeline(format: 'heic'));

        $result = $driver->process($contents, $this->pipeline(new Cover(50, 25)));

        $this->assertStringContainsString('ftyp', substr($result, 0, 16));
        $this->assertMatchesRegularExpression('/heic|heix|hevc|hevx|mif1/', substr($result, 0, 32));

        $result = $driver->process($result, $this->pipeline(format: 'jpg'));

        $this->assertSame([50, 25], array_slice(getimagesizefromstring($result), 0, 2));
    }

    public function testDimensionsReturnsTheDecodedSize(): void
    {
        $driver = new ImagickDriver;
        $contents = $driver->process($this->fakeImageContents(320, 240), $this->pipeline(new Cover(200, 150), format: 'png'));

        $this->assertSame([200, 150], $driver->dimensions($contents));
    }

    public function testDimensionsReturnsTheDisplaySizeForHeic(): void
    {
        $this->ensureImageFormatCanBeEncoded('heic');

        $driver = new ImagickDriver;

        // 137x73 is a size where HEIC's coded/padded frame differs from the display size, which
        // getimagesize() misreports; the driver must return the true display size.
        $contents = $driver->process($this->fakeImageContents(400, 300), $this->pipeline(new Cover(137, 73), format: 'heic'));

        $this->assertSame([137, 73], $driver->dimensions($contents));
    }

    public function testImageDimensionsAreCorrectForRealHeicThroughPublicApi(): void
    {
        $this->ensureImageFormatCanBeEncoded('heic');

        // The public API must use the driver dimensions instead of HEIC's padded native frame size.
        $heic = (new ImagickDriver)->process(
            $this->fakeImageContents(400, 300),
            $this->pipeline(new Cover(137, 73), format: 'heic')
        );

        $container = new Container;
        $container->instance('config', new Repository(['images' => ['default' => 'imagick']]));
        $container->instance('image', new ImageManager($container));
        Container::setInstance($container);

        try {
            $this->assertSame([137, 73], (new Image($heic))->dimensions());
        } finally {
            Container::setInstance(null);
        }
    }

    public function testProcessesOptimizeToBmp(): void
    {
        $driver = new ImagickDriver;

        $pipeline = $this->pipeline(format: 'bmp');

        $result = $driver->process($this->fakeImageContents(), $pipeline);

        $this->assertSame(IMAGETYPE_BMP, getimagesizefromstring($result)[2]);
    }

    public function testProcessesCoverAndOptimizeTogether(): void
    {
        $this->ensureImageFormatCanBeEncoded('webp');

        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(300, 300);

        $pipeline = $this->pipeline(new Cover(75, 75), format: 'webp');

        $result = $driver->process($contents, $pipeline);

        [$width, $height, $type] = getimagesizefromstring($result);

        $this->assertSame(75, $width);
        $this->assertSame(75, $height);
        $this->assertSame(IMAGETYPE_WEBP, $type);
    }

    public function testProcessesContain(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(400, 200);

        $pipeline = $this->pipeline(new Contain(200, 200, '#ffffff'));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(200, $width);
        $this->assertSame(200, $height);
    }

    public function testProcessesContainWithDominantBackground(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->solidColorImageContents(255, 0, 0, 400, 200);

        $pipeline = $this->pipeline(new Contain(200, 200, 'dominant'));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(200, $width);
        $this->assertSame(200, $height);
    }

    public function testDominantColorReturnsHexForSolidImage(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->solidColorImageContents(0, 128, 255);

        $this->assertSame('#0080ff', $driver->dominantColor($contents));
    }

    public function testDominantColorIgnoresAlphaChannel(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->semiTransparentColorImageContents(0, 128, 255, 128);

        $this->assertSame('#0080ff', $driver->dominantColor($contents));
    }

    public function testProcessesCrop(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(400, 200);

        $pipeline = $this->pipeline(new Crop(100, 50, 10, 20));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(100, $width);
        $this->assertSame(50, $height);
    }

    public function testProcessesResize(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(400, 200);

        $pipeline = $this->pipeline(new Resize(200, 200));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(200, $width);
        $this->assertSame(200, $height);
    }

    public function testProcessesRotate(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(100, 50);

        $pipeline = $this->pipeline(new Rotate(90));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(50, $width);
        $this->assertSame(100, $height);
    }

    public function testProcessesRotateWithDominantBackground(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->solidColorImageContents(0, 255, 0, 100, 50);

        $pipeline = $this->pipeline(new Rotate(45, 'dominant'));

        $result = $driver->process($contents, $pipeline);

        $this->assertNotEmpty($result);
        $this->assertNotFalse(getimagesizefromstring($result));
    }

    public function testProcessesScale(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(400, 200);

        $pipeline = $this->pipeline(new Scale(200, 200));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(200, $width);
        $this->assertSame(100, $height);
    }

    public function testProcessesScaleWidthOnly(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(400, 200);

        $pipeline = $this->pipeline(new Scale(200, null));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(200, $width);
        $this->assertSame(100, $height);
    }

    public function testProcessesScaleHeightOnly(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(400, 200);

        $pipeline = $this->pipeline(new Scale(null, 100));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(200, $width);
        $this->assertSame(100, $height);
    }

    public function testScaleDoesNotUpscale(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(100, 80);

        $pipeline = $this->pipeline(new Scale(800, 600));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(100, $width);
        $this->assertSame(80, $height);
    }

    public function testFormatConversionPreservesDimensions(): void
    {
        $this->ensureImageFormatCanBeEncoded('webp');

        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(300, 200);

        $pipeline = $this->pipeline(format: 'webp');

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(300, $width);
        $this->assertSame(200, $height);
    }

    public function testQualityPreservesDimensions(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(300, 200);

        $pipeline = $this->pipeline(quality: 50);

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(300, $width);
        $this->assertSame(200, $height);
    }

    public function testProcessesOrient(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(100, 100);

        $pipeline = $this->pipeline(new Orient);

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(100, $width);
        $this->assertSame(100, $height);
    }

    public function testProcessesBlur(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(100, 100);

        $pipeline = $this->pipeline(new Blur(10));

        $result = $driver->process($contents, $pipeline);

        $this->assertNotEmpty($result);
        $this->assertNotSame($contents, $result);
    }

    public function testProcessesGrayscale(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(100, 100);

        $pipeline = $this->pipeline(new Grayscale);

        $result = $driver->process($contents, $pipeline);

        $this->assertNotEmpty($result);
        $this->assertNotSame($contents, $result);
    }

    public function testProcessesSharpen(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(100, 100);

        $pipeline = $this->pipeline(new Sharpen(10));

        $result = $driver->process($contents, $pipeline);

        $this->assertNotEmpty($result);
        $this->assertNotSame($contents, $result);
    }

    public function testProcessesFlipVertically(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(100, 100);

        $pipeline = $this->pipeline(new FlipVertically);

        $result = $driver->process($contents, $pipeline);

        $this->assertNotEmpty($result);
    }

    public function testProcessesFlipHorizontally(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(100, 100);

        $pipeline = $this->pipeline(new FlipHorizontally);

        $result = $driver->process($contents, $pipeline);

        $this->assertNotEmpty($result);
    }

    public function testProcessesCustomTransformation(): void
    {
        $driver = new ImagickDriver;
        $transformation = new readonly class implements Transformation {
        };
        $received = null;

        $driver->transformUsing($transformation::class, function (ImageInterface $image, Transformation $transformation) use (&$received) {
            $received = $transformation;

            return $image->scaleDown(50, 50);
        });

        $result = $driver->process($this->fakeImageContents(100, 100), $this->pipeline($transformation));

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame($transformation, $received);
        $this->assertSame(50, $width);
        $this->assertSame(50, $height);
    }

    public function testThrowsForUnsupportedInputFormat(): void
    {
        $driver = new ImagickDriver;

        $this->expectExceptionObject(new ImageException('The image format [text/plain] is not supported.'));

        $driver->process('not-an-image', new ImagePipeline);
    }

    public function testReturnsImageWithoutOptions(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(100, 100);

        $result = $driver->process($contents, new ImagePipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(100, $width);
        $this->assertSame(100, $height);
    }

    public function testQualityAffectsFileSize(): void
    {
        $driver = new ImagickDriver;
        $contents = $this->fakeImageContents(100, 100);

        $lowQuality = $this->pipeline(format: 'jpg', quality: 1);
        $highQuality = $this->pipeline(format: 'jpg', quality: 100);

        $lowResult = $driver->process($contents, $lowQuality);
        $highResult = $driver->process($contents, $highQuality);

        $this->assertLessThan(strlen($highResult), strlen($lowResult));
    }

    public function testEnsureRequirementsPasses(): void
    {
        $driver = new ImagickDriver;

        $driver->ensureRequirementsAreMet();

        $this->assertTrue(true);
    }

    protected function fakeImageContents(int $width = 100, int $height = 100): string
    {
        $file = UploadedFile::fake()->image('test.jpg', $width, $height);

        return file_get_contents($file->getRealPath());
    }

    protected function ensureImageFormatCanBeEncoded(string $format): void
    {
        if (Imagick::queryFormats(strtoupper($format)) === []) {
            $this->markTestSkipped("The Imagick extension was not compiled with {$format} support.");
        }

        $imagick = null;

        try {
            $imagick = new Imagick;
            $imagick->newImage(1, 1, 'white');
            $imagick->setImageFormat($format);
            $encoded = $imagick->getImageBlob();
        } catch (ImagickException) {
            // Some builds ship the HEIC decode delegate but no encoder, in which case encoding
            // raises instead of returning an empty blob. Either way, encoding is unavailable.
            $encoded = '';
        } finally {
            if ($imagick instanceof Imagick) {
                $imagick->clear();
                $imagick->destroy();
            }
        }

        if ($encoded === '') {
            $this->markTestSkipped("The Imagick extension cannot encode {$format} images.");
        }
    }

    protected function solidColorImageContents(int $red, int $green, int $blue, int $width = 100, int $height = 100): string
    {
        $imagick = new Imagick;
        $imagick->newImage($width, $height, new ImagickPixel(sprintf('rgb(%d,%d,%d)', $red, $green, $blue)));
        $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_OPAQUE);
        $imagick->setImageFormat('png');

        $contents = $imagick->getImageBlob();
        $imagick->clear();
        $imagick->destroy();

        return $contents;
    }

    protected function semiTransparentColorImageContents(int $red, int $green, int $blue, int $alpha, int $width = 100, int $height = 100): string
    {
        $imagick = new Imagick;
        $imagick->newImage($width, $height, new ImagickPixel(sprintf('rgba(%d,%d,%d,%.2f)', $red, $green, $blue, $alpha / 255)));
        $imagick->setImageFormat('png');

        $contents = $imagick->getImageBlob();
        $imagick->clear();
        $imagick->destroy();

        return $contents;
    }

    protected function pipeline(?Transformation $transformation = null, ?string $format = null, ?int $quality = null): ImagePipeline
    {
        $pipeline = new ImagePipeline;

        if ($transformation !== null) {
            $pipeline->add($transformation);
        }

        $pipeline->output->format = $format;
        $pipeline->output->quality = $quality;

        return $pipeline;
    }
}
