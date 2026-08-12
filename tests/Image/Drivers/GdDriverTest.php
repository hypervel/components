<?php

declare(strict_types=1);

namespace Hypervel\Tests\Image\Drivers;

use Hypervel\Contracts\Image\Transformation;
use Hypervel\Http\UploadedFile;
use Hypervel\Image\Drivers\GdDriver;
use Hypervel\Image\ImageException;
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
use Intervention\Image\Interfaces\ImageInterface;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresPhpExtension('gd')]
class GdDriverTest extends TestCase
{
    public function testProcessesCover(): void
    {
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(200, 200);

        $pipeline = $this->pipeline(new Cover(100, 50));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(100, $width);
        $this->assertSame(50, $height);
    }

    public function testProcessesOptimizeToWebp(): void
    {
        $driver = new GdDriver;

        $pipeline = $this->pipeline(format: 'webp');

        $result = $driver->process($this->fakeImageContents(), $pipeline);

        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($result)[2]);
    }

    public function testProcessesOptimizeToJpeg(): void
    {
        $driver = new GdDriver;

        $pipeline = $this->pipeline(format: 'jpg');

        $result = $driver->process($this->fakeImageContents(), $pipeline);

        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($result)[2]);
    }

    public function testProcessesOptimizeToPng(): void
    {
        $driver = new GdDriver;

        $pipeline = $this->pipeline(format: 'png');

        $result = $driver->process($this->fakeImageContents(), $pipeline);

        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($result)[2]);
    }

    public function testProcessesOptimizeToGif(): void
    {
        $driver = new GdDriver;

        $pipeline = $this->pipeline(format: 'gif');

        $result = $driver->process($this->fakeImageContents(), $pipeline);

        $this->assertSame(IMAGETYPE_GIF, getimagesizefromstring($result)[2]);
    }

    #[RequiresFunction('imageavif')]
    public function testProcessesOptimizeToAvif(): void
    {
        $driver = new GdDriver;

        $pipeline = $this->pipeline(format: 'avif');

        $result = $driver->process($this->fakeImageContents(), $pipeline);

        $this->assertSame(IMAGETYPE_AVIF, getimagesizefromstring($result)[2]);
    }

    #[RequiresFunction('imageavif')]
    public function testProcessesAvifInput(): void
    {
        $driver = new GdDriver;
        $contents = $driver->process($this->fakeImageContents(), $this->pipeline(format: 'avif'));

        $result = $driver->process($contents, $this->pipeline(new Cover(50, 25), format: 'jpg'));

        $this->assertSame([50, 25], array_slice(getimagesizefromstring($result), 0, 2));
    }

    public function testProcessesOptimizeToBmp(): void
    {
        $driver = new GdDriver;

        $pipeline = $this->pipeline(format: 'bmp');

        $result = $driver->process($this->fakeImageContents(), $pipeline);

        $this->assertSame(IMAGETYPE_BMP, getimagesizefromstring($result)[2]);
    }

    public function testProcessesCoverAndOptimizeTogether(): void
    {
        $driver = new GdDriver;
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
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(400, 200);

        $pipeline = $this->pipeline(new Contain(200, 200, '#ffffff'));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(200, $width);
        $this->assertSame(200, $height);
    }

    public function testProcessesContainWithDominantBackground(): void
    {
        $driver = new GdDriver;
        $contents = $this->solidColorImageContents(255, 0, 0, 400, 200);

        $pipeline = $this->pipeline(new Contain(200, 200, 'dominant'));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(200, $width);
        $this->assertSame(200, $height);
    }

    public function testDominantColorReturnsHexForSolidImage(): void
    {
        $driver = new GdDriver;
        $contents = $this->solidColorImageContents(0, 128, 255);

        $this->assertSame('#0080ff', $driver->dominantColor($contents));
    }

    public function testDominantColorIgnoresAlphaChannel(): void
    {
        $driver = new GdDriver;
        $contents = $this->semiTransparentColorImageContents(0, 128, 255, 128);

        $this->assertSame('#0080ff', $driver->dominantColor($contents));
    }

    public function testProcessesCrop(): void
    {
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(400, 200);

        $pipeline = $this->pipeline(new Crop(100, 50, 10, 20));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(100, $width);
        $this->assertSame(50, $height);
    }

    public function testProcessesResize(): void
    {
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(400, 200);

        $pipeline = $this->pipeline(new Resize(200, 200));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(200, $width);
        $this->assertSame(200, $height);
    }

    public function testProcessesRotate(): void
    {
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(100, 50);

        $pipeline = $this->pipeline(new Rotate(90));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(50, $width);
        $this->assertSame(100, $height);
    }

    public function testProcessesRotateWithDominantBackground(): void
    {
        $driver = new GdDriver;
        $contents = $this->solidColorImageContents(0, 255, 0, 100, 50);

        $pipeline = $this->pipeline(new Rotate(45, 'dominant'));

        $result = $driver->process($contents, $pipeline);

        $this->assertNotEmpty($result);
        $this->assertNotFalse(getimagesizefromstring($result));
    }

    public function testProcessesScale(): void
    {
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(400, 200);

        $pipeline = $this->pipeline(new Scale(200, 200));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(200, $width);
        $this->assertSame(100, $height);
    }

    public function testProcessesScaleWidthOnly(): void
    {
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(400, 200);

        $pipeline = $this->pipeline(new Scale(200, null));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(200, $width);
        $this->assertSame(100, $height);
    }

    public function testProcessesScaleHeightOnly(): void
    {
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(400, 200);

        $pipeline = $this->pipeline(new Scale(null, 100));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(200, $width);
        $this->assertSame(100, $height);
    }

    public function testScaleDoesNotUpscale(): void
    {
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(100, 80);

        $pipeline = $this->pipeline(new Scale(800, 600));

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(100, $width);
        $this->assertSame(80, $height);
    }

    public function testFormatConversionPreservesDimensions(): void
    {
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(300, 200);

        $pipeline = $this->pipeline(format: 'webp');

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(300, $width);
        $this->assertSame(200, $height);
    }

    public function testQualityPreservesDimensions(): void
    {
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(300, 200);

        $pipeline = $this->pipeline(quality: 50);

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(300, $width);
        $this->assertSame(200, $height);
    }

    public function testProcessesOrient(): void
    {
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(100, 100);

        $pipeline = $this->pipeline(new Orient);

        $result = $driver->process($contents, $pipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(100, $width);
        $this->assertSame(100, $height);
    }

    public function testProcessesBlur(): void
    {
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(100, 100);

        $pipeline = $this->pipeline(new Blur(10));

        $result = $driver->process($contents, $pipeline);

        $this->assertNotEmpty($result);
        $this->assertNotSame($contents, $result);
    }

    public function testProcessesGrayscale(): void
    {
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(100, 100);

        $pipeline = $this->pipeline(new Grayscale);

        $result = $driver->process($contents, $pipeline);

        $this->assertNotEmpty($result);
        $this->assertNotSame($contents, $result);
    }

    public function testProcessesSharpen(): void
    {
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(100, 100);

        $pipeline = $this->pipeline(new Sharpen(10));

        $result = $driver->process($contents, $pipeline);

        $this->assertNotEmpty($result);
        $this->assertNotSame($contents, $result);
    }

    public function testProcessesFlipVertically(): void
    {
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(100, 100);

        $pipeline = $this->pipeline(new FlipVertically);

        $result = $driver->process($contents, $pipeline);

        $this->assertNotEmpty($result);
    }

    public function testProcessesFlipHorizontally(): void
    {
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(100, 100);

        $pipeline = $this->pipeline(new FlipHorizontally);

        $result = $driver->process($contents, $pipeline);

        $this->assertNotEmpty($result);
    }

    public function testProcessesCustomTransformation(): void
    {
        $driver = new GdDriver;
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
        $driver = new GdDriver;

        $this->expectExceptionObject(new ImageException('The image format [text/plain] is not supported.'));

        $driver->process('not-an-image', new ImagePipeline);
    }

    public function testReturnsImageWithoutOptions(): void
    {
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(100, 100);

        $result = $driver->process($contents, new ImagePipeline);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(100, $width);
        $this->assertSame(100, $height);
    }

    public function testQualityAffectsFileSize(): void
    {
        $driver = new GdDriver;
        $contents = $this->fakeImageContents(100, 100);

        $lowQuality = $this->pipeline(format: 'jpg', quality: 1);
        $highQuality = $this->pipeline(format: 'jpg', quality: 100);

        $lowResult = $driver->process($contents, $lowQuality);
        $highResult = $driver->process($contents, $highQuality);

        $this->assertLessThan(strlen($highResult), strlen($lowResult));
    }

    public function testEnsureRequirementsPasses(): void
    {
        $driver = new GdDriver;

        $driver->ensureRequirementsAreMet();

        $this->assertTrue(true);
    }

    public function testDimensionsReturnsTheDecodedSize(): void
    {
        $driver = new GdDriver;
        $contents = $driver->process($this->fakeImageContents(320, 240), $this->pipeline(new Cover(200, 150), format: 'png'));

        $this->assertSame([200, 150], $driver->dimensions($contents));
    }

    protected function fakeImageContents(int $width = 100, int $height = 100): string
    {
        $file = UploadedFile::fake()->image('test.jpg', $width, $height);

        return file_get_contents($file->getRealPath());
    }

    protected function solidColorImageContents(int $red, int $green, int $blue, int $width = 100, int $height = 100): string
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, $red, $green, $blue);
        imagefill($image, 0, 0, $color);

        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    protected function semiTransparentColorImageContents(int $red, int $green, int $blue, int $alpha, int $width = 100, int $height = 100): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        // GD alpha runs 0 (opaque) to 127 (fully transparent), the inverse of a 0-255 alpha channel.
        $gdAlpha = (int) round((255 - $alpha) / 255 * 127);
        $color = imagecolorallocatealpha($image, $red, $green, $blue, $gdAlpha);
        imagefill($image, 0, 0, $color);

        ob_start();
        imagepng($image);

        return ob_get_clean();
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
