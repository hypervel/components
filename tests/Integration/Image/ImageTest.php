<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Image;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\UploadedFile;
use Hypervel\Image\Image;
use Hypervel\Image\ImageServiceProvider;
use Hypervel\Support\Facades\Image as ImageFacade;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresPhpExtension('gd')]
class ImageTest extends TestCase
{
    protected function getPackageProviders(Application $app): array
    {
        return [ImageServiceProvider::class];
    }

    public function testCoverAndToBytes(): void
    {
        $image = new Image($this->fakeImageContents(200, 200));

        $result = $image->cover(100, 100)->toBytes();

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(100, $width);
        $this->assertSame(100, $height);
    }

    public function testScaleAndToBytes(): void
    {
        $image = new Image($this->fakeImageContents(400, 200));

        $result = $image->scale(200, 200)->toBytes();

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(200, $width);
        $this->assertSame(100, $height);
    }

    public function testContainAndToBytes(): void
    {
        $image = new Image($this->fakeImageContents(400, 200));

        $result = $image->contain(200, 200, '#ffffff')->toBytes();

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(200, $width);
        $this->assertSame(200, $height);
    }

    public function testContainWithDominantBackground(): void
    {
        $image = new Image($this->solidColorImageContents(255, 0, 0, 400, 200));

        $result = $image->contain(200, 200, 'dominant')->toBytes();

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(200, $width);
        $this->assertSame(200, $height);
    }

    public function testDominantColorReturnsHex(): void
    {
        $image = new Image($this->solidColorImageContents(0, 128, 255));

        $this->assertSame('#0080ff', $image->dominantColor());
    }

    public function testCropAndToBytes(): void
    {
        $image = new Image($this->fakeImageContents(400, 200));

        $result = $image->crop(100, 50, 10, 20)->toBytes();

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(100, $width);
        $this->assertSame(50, $height);
    }

    public function testResizeAndToBytes(): void
    {
        $image = new Image($this->fakeImageContents(400, 200));

        $result = $image->resize(200, 200)->toBytes();

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(200, $width);
        $this->assertSame(200, $height);
    }

    public function testRotateAndToBytes(): void
    {
        $image = new Image($this->fakeImageContents(100, 50));

        $result = $image->rotate(90)->toBytes();

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(50, $width);
        $this->assertSame(100, $height);
    }

    public function testRotateWithDominantBackground(): void
    {
        $image = new Image($this->solidColorImageContents(0, 255, 0, 100, 50));

        $result = $image->rotate(45, 'dominant')->toBytes();

        $this->assertNotEmpty($result);
        $this->assertNotFalse(getimagesizefromstring($result));
    }

    public function testToPngAndToBytes(): void
    {
        $image = new Image($this->fakeImageContents(100, 100));

        $result = $image->toPng()->toBytes();

        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($result)[2]);
    }

    public function testToWebpAndToBytes(): void
    {
        $image = new Image($this->fakeImageContents(100, 100));

        $result = $image->toWebp()->toBytes();

        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($result)[2]);
    }

    public function testBlurAndToBytes(): void
    {
        $contents = $this->fakeImageContents(100, 100);
        $image = new Image($contents);

        $result = $image->blur(10)->toBytes();

        $this->assertNotSame($contents, $result);
    }

    public function testGrayscaleAndToBytes(): void
    {
        $contents = $this->fakeImageContents(100, 100);
        $image = new Image($contents);

        $result = $image->grayscale()->toBytes();

        $this->assertNotSame($contents, $result);
    }

    public function testImmutabilityWithVariants(): void
    {
        $image = new Image($this->fakeImageContents(400, 400));

        $thumb = $image->cover(100, 100)->toWebp();
        $large = $image->scale(200, 200)->toWebp();

        $thumbBytes = $thumb->toBytes();
        $largeBytes = $large->toBytes();

        $thumbSize = getimagesizefromstring($thumbBytes);
        $largeSize = getimagesizefromstring($largeBytes);

        $this->assertSame(100, $thumbSize[0]);
        $this->assertSame(100, $thumbSize[1]);
        $this->assertSame(IMAGETYPE_WEBP, $thumbSize[2]);

        $this->assertSame(200, $largeSize[0]);
        $this->assertSame(200, $largeSize[1]);
        $this->assertSame(IMAGETYPE_WEBP, $largeSize[2]);
    }

    public function testStoreSavesToDisk(): void
    {
        Storage::fake('local');

        $image = new Image($this->fakeImageContents(100, 100));

        $image->toWebp()->store('images', 'local');

        $files = Storage::disk('local')->files('images');

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.webp', $files[0]);
    }

    public function testStoreAsSavesWithCustomName(): void
    {
        Storage::fake('local');

        $image = new Image($this->fakeImageContents(100, 100));

        $image->toWebp()->storeAs('images', 'avatar.webp', 'local');

        Storage::disk('local')->assertExists('images/avatar.webp');
    }

    public function testMimeTypeAfterFormatConversion(): void
    {
        $image = new Image($this->fakeImageContents(100, 100));

        $this->assertSame('image/webp', $image->toWebp()->mimeType());
    }

    public function testExtensionAfterFormatConversion(): void
    {
        $image = new Image($this->fakeImageContents(100, 100));

        $this->assertSame('webp', $image->toWebp()->extension());
        $this->assertSame('jpg', $image->extension());
    }

    public function testDimensionsAfterCover(): void
    {
        $image = new Image($this->fakeImageContents(400, 300));

        $this->assertSame([200, 200], $image->cover(200, 200)->dimensions());
        $this->assertSame([400, 300], $image->dimensions());
    }

    public function testQualityAffectsFileSize(): void
    {
        $image = new Image($this->fakeImageContents(100, 100));

        $low = $image->toJpg()->quality(1)->toBytes();
        $high = $image->toJpg()->quality(100)->toBytes();

        $this->assertLessThan(strlen($high), strlen($low));
    }

    public function testFullAvatarPipeline(): void
    {
        Storage::fake('local');

        $image = new Image($this->fakeImageContents(800, 600));

        $result = $image->orient()->cover(200, 200)->toWebp()->quality(80);
        $result->store('avatars', 'local');

        $this->assertSame([200, 200], $result->dimensions());
        $this->assertSame('image/webp', $result->mimeType());

        $files = Storage::disk('local')->files('avatars');
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.webp', $files[0]);
    }

    public function testTwoVariantsFromUploadedFile(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->image('photo.jpg', 800, 600);
        $image = new Image(fn () => $file->getContent(), $file);

        $thumb = $image->cover(100, 100)->toWebp();
        $large = $image->scale(400, 400)->toWebp();

        $thumb->store('thumbs', 'local');
        $large->store('photos', 'local');

        $thumbFiles = Storage::disk('local')->files('thumbs');
        $largeFiles = Storage::disk('local')->files('photos');

        $this->assertCount(1, $thumbFiles);
        $this->assertCount(1, $largeFiles);

        $thumbBytes = Storage::disk('local')->get($thumbFiles[0]);
        $largeBytes = Storage::disk('local')->get($largeFiles[0]);

        $thumbSize = getimagesizefromstring($thumbBytes);
        $largeSize = getimagesizefromstring($largeBytes);

        $this->assertSame(100, $thumbSize[0]);
        $this->assertSame(100, $thumbSize[1]);
        $this->assertSame(IMAGETYPE_WEBP, $thumbSize[2]);

        $this->assertLessThanOrEqual(400, $largeSize[0]);
        $this->assertLessThanOrEqual(400, $largeSize[1]);
        $this->assertSame(IMAGETYPE_WEBP, $largeSize[2]);

        $this->assertSame($file, $image->file());
        $this->assertSame($file, $thumb->file());
        $this->assertSame($file, $large->file());
    }

    public function testTwoVariantsFromRequestImage(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->image('avatar.jpg', 600, 600);

        $image = new Image(fn () => $file->getContent(), $file);

        $avatar = $image->orient()->cover(200, 200)->toWebp();
        $placeholder = $image->scale(40, 40)->blur(15)->toWebp()->quality(50);

        $avatar->store('avatars', 'local');
        $placeholder->store('placeholders', 'local');

        $avatarFiles = Storage::disk('local')->files('avatars');
        $placeholderFiles = Storage::disk('local')->files('placeholders');

        $this->assertCount(1, $avatarFiles);
        $this->assertCount(1, $placeholderFiles);

        $avatarSize = getimagesizefromstring(Storage::disk('local')->get($avatarFiles[0]));
        $placeholderSize = getimagesizefromstring(Storage::disk('local')->get($placeholderFiles[0]));

        $this->assertSame(200, $avatarSize[0]);
        $this->assertSame(200, $avatarSize[1]);

        $this->assertSame(40, $placeholderSize[0]);
        $this->assertSame(40, $placeholderSize[1]);

        $this->assertSame([600, 600], $image->dimensions());
        $this->assertSame('avatar.jpg', $image->file()->getClientOriginalName());
    }

    public function testFromPathFacadeCreatesImage(): void
    {
        $file = UploadedFile::fake()->image('test.jpg', 200, 200);

        $image = ImageFacade::fromPath($file->getRealPath());

        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame([200, 200], $image->dimensions());
    }

    public function testFromBytesFacadeCreatesImage(): void
    {
        $contents = $this->fakeImageContents(150, 150);

        $image = ImageFacade::fromBytes($contents);

        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame([150, 150], $image->dimensions());
    }

    public function testFromBase64FacadeCreatesImage(): void
    {
        $contents = $this->fakeImageContents(120, 120);

        $image = ImageFacade::fromBase64(base64_encode($contents));

        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame([120, 120], $image->dimensions());
    }

    public function testStorageImageCreatesImage(): void
    {
        Storage::fake('local');

        $contents = $this->fakeImageContents(300, 200);
        Storage::disk('local')->put('photos/test.jpg', $contents);

        $image = Storage::disk('local')->image('photos/test.jpg');

        $this->assertInstanceOf(Image::class, $image);
        $this->assertSame([300, 200], $image->dimensions());
    }

    public function testSharpenAfterScale(): void
    {
        $image = new Image($this->fakeImageContents(400, 400));

        $result = $image->scale(200, 200)->sharpen(10)->toBytes();

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(200, $width);
        $this->assertSame(200, $height);
    }

    public function testFlipVerticallyPreservesDimensions(): void
    {
        $image = new Image($this->fakeImageContents(300, 200));

        $result = $image->flipVertically()->toBytes();

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(300, $width);
        $this->assertSame(200, $height);
    }

    public function testFlipHorizontallyPreservesDimensions(): void
    {
        $image = new Image($this->fakeImageContents(300, 200));

        $result = $image->flipHorizontally()->toBytes();

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(300, $width);
        $this->assertSame(200, $height);
    }

    public function testFlipVerticallyAndHorizontallyTogether(): void
    {
        $image = new Image($this->fakeImageContents(200, 200));

        $result = $image->flipVertically()->flipHorizontally()->toBytes();

        $this->assertNotEmpty($result);
        $this->assertSame([200, 200], getimagesizefromstring($result) ? [getimagesizefromstring($result)[0], getimagesizefromstring($result)[1]] : [0, 0]);
    }

    public function testAllOperationsCombined(): void
    {
        $image = new Image($this->fakeImageContents(800, 600));

        $result = $image
            ->orient()
            ->cover(200, 200)
            ->blur(5)
            ->grayscale()
            ->sharpen(10)
            ->flipVertically()
            ->toWebp()
            ->quality(80);

        $bytes = $result->toBytes();
        $size = getimagesizefromstring($bytes);

        $this->assertSame(200, $size[0]);
        $this->assertSame(200, $size[1]);
        $this->assertSame(IMAGETYPE_WEBP, $size[2]);
    }

    public function testToBytesIsIdempotent(): void
    {
        $image = new Image($this->fakeImageContents(100, 100));
        $processed = $image->cover(50, 50)->toWebp();

        $first = $processed->toBytes();
        $second = $processed->toBytes();

        $this->assertSame($first, $second);
    }

    public function testMaterializingBeforeAppendingPreservesOneFullRecipe(): void
    {
        $image = new Image($this->fakeImageContents(100, 50));
        $materialized = $image->rotate(90)->toJpg()->quality(10);

        $materialized->toBytes();

        $appended = $materialized->rotate(90)->toPng();
        $direct = $image->rotate(90)->rotate(90)->toPng()->quality(10);

        $this->assertSame($direct->toBytes(), $appended->toBytes());
        $this->assertSame([100, 50], $appended->dimensions());
        $this->assertSame('image/png', $appended->mimeType());
    }

    public function testWidthAndHeightHelpers(): void
    {
        $image = new Image($this->fakeImageContents(400, 300));
        $covered = $image->cover(200, 150);

        $this->assertSame(200, $covered->width());
        $this->assertSame(150, $covered->height());
    }

    public function testToBase64ProducesValidBase64(): void
    {
        $image = new Image($this->fakeImageContents(100, 100));
        $result = $image->cover(50, 50)->toWebp();

        $base64 = $result->toBase64();

        $this->assertNotFalse(base64_decode($base64, true));
        $this->assertSame($result->toBytes(), base64_decode($base64));
    }

    public function testToDataUriProducesValidDataUri(): void
    {
        $image = new Image($this->fakeImageContents(100, 100));
        $result = $image->toWebp();

        $dataUri = $result->toDataUri();

        $this->assertStringStartsWith('data:image/webp;base64,', $dataUri);
    }

    public function testStoreWithStringDiskOption(): void
    {
        Storage::fake('custom');

        $image = new Image($this->fakeImageContents(100, 100));
        $image->toWebp()->store('images', 'custom');

        $files = Storage::disk('custom')->files('images');

        $this->assertCount(1, $files);
    }

    public function testStoreWithArrayOptions(): void
    {
        Storage::fake('custom');

        $image = new Image($this->fakeImageContents(100, 100));
        $image->toWebp()->store('images', 'custom', ['visibility' => 'public']);

        $files = Storage::disk('custom')->files('images');

        $this->assertCount(1, $files);
    }

    public function testSecondCoverOverridesFirst(): void
    {
        $image = new Image($this->fakeImageContents(400, 400));

        $result = $image->cover(200, 200)->cover(100, 100)->toBytes();

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(100, $width);
        $this->assertSame(100, $height);
    }

    public function testBranchingAfterToBytesDoesNotReapplyStaleTransformations(): void
    {
        $image = new Image($this->fakeImageContents(100, 50));

        $rotatedOnce = $image->rotate(90);
        $rotatedOnce->toBytes();

        $rotatedTwice = $rotatedOnce->rotate(90);

        [$width, $height] = getimagesizefromstring($rotatedTwice->toBytes());

        $this->assertSame(100, $width);
        $this->assertSame(50, $height);
    }

    public function testStoreAsWithNameOnly(): void
    {
        Storage::fake('local');

        $image = new Image($this->fakeImageContents(100, 100));

        $image->storeAs('avatar.jpg', disk: 'local');

        Storage::disk('local')->assertExists('avatar.jpg');
    }

    public function testStorePubliclySetsVisibility(): void
    {
        Storage::fake('local');

        $image = new Image($this->fakeImageContents(100, 100));

        $image->toWebp()->storePublicly('images', 'local');

        $files = Storage::disk('local')->files('images');

        $this->assertCount(1, $files);
    }

    public function testStorePubliclyAs(): void
    {
        Storage::fake('local');

        $image = new Image($this->fakeImageContents(100, 100));

        $image->toWebp()->storePubliclyAs('images', 'public-avatar.webp', 'local');

        Storage::disk('local')->assertExists('images/public-avatar.webp');
    }

    public function testStoreWithEmptyPath(): void
    {
        Storage::fake('local');

        $image = new Image($this->fakeImageContents(100, 100));

        $image->store('', 'local');

        $files = Storage::disk('local')->allFiles();

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.jpg', $files[0]);
    }

    public function testHashNameChangesExtensionAfterFormatConversion(): void
    {
        $image = new Image($this->fakeImageContents(100, 100));

        $jpgName = $image->hashName();
        $webpName = $image->toWebp()->hashName();

        $this->assertStringEndsWith('.jpg', $jpgName);
        $this->assertStringEndsWith('.webp', $webpName);
    }

    public function testToJpgConversion(): void
    {
        $image = new Image($this->fakeImageContents(100, 100));

        $result = $image->toJpg()->toBytes();

        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($result)[2]);
    }

    public function testToJpegAliasWorks(): void
    {
        $image = new Image($this->fakeImageContents(100, 100));

        $result = $image->toJpeg()->toBytes();

        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($result)[2]);
    }

    public function testOptimizeShortcutProducesWebp(): void
    {
        $image = new Image($this->fakeImageContents(100, 100));

        $result = $image->optimize()->toBytes();

        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($result)[2]);
    }

    public function testOrientDoesNotChangeDimensionsOnNonRotatedImage(): void
    {
        $image = new Image($this->fakeImageContents(300, 200));

        $result = $image->orient();

        $this->assertSame(300, $result->width());
        $this->assertSame(200, $result->height());
    }

    public function testGrayscaleDoesNotChangeDimensions(): void
    {
        $image = new Image($this->fakeImageContents(200, 150));

        $result = $image->grayscale();

        $this->assertSame(200, $result->width());
        $this->assertSame(150, $result->height());
    }

    public function testQualityAloneChangesFileSize(): void
    {
        $image = new Image($this->fakeImageContents(200, 200));

        $default = $image->toBytes();
        $low = $image->quality(1)->toBytes();

        $this->assertNotSame(strlen($default), strlen($low));
    }

    public function testRequestImageReturnsImageWithFile(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg', 100, 100);
        $image = new Image(fn () => $file->getContent(), $file);

        $this->assertNotNull($image->file());
        $this->assertSame('avatar.jpg', $image->file()->getClientOriginalName());
        $this->assertSame([100, 100], $image->dimensions());
    }

    public function testFormatConversionDoesNotChangeDimensions(): void
    {
        $image = new Image($this->fakeImageContents(300, 200));

        $webp = $image->toWebp()->toBytes();
        $jpg = $image->toJpg()->toBytes();

        [$webpWidth, $webpHeight] = getimagesizefromstring($webp);
        [$jpgWidth, $jpgHeight] = getimagesizefromstring($jpg);

        $this->assertSame(300, $webpWidth);
        $this->assertSame(200, $webpHeight);
        $this->assertSame(300, $jpgWidth);
        $this->assertSame(200, $jpgHeight);
    }

    public function testQualityDoesNotChangeDimensions(): void
    {
        $image = new Image($this->fakeImageContents(300, 200));

        $result = $image->quality(50)->toBytes();

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(300, $width);
        $this->assertSame(200, $height);
    }

    public function testQualityAndFormatDoesNotChangeDimensions(): void
    {
        $image = new Image($this->fakeImageContents(300, 200));

        $result = $image->quality(90)->toWebp()->toBytes();

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(300, $width);
        $this->assertSame(200, $height);
    }

    public function testScaleDownDoesNotUpscale(): void
    {
        $image = new Image($this->fakeImageContents(100, 80));

        $result = $image->scale(800, 600)->toBytes();

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(100, $width);
        $this->assertSame(80, $height);
    }

    public function testScaleDownShrinksLargerImages(): void
    {
        $image = new Image($this->fakeImageContents(800, 600));

        $result = $image->scale(400, 400)->toBytes();

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(400, $width);
        $this->assertSame(300, $height);
    }

    public function testStoreWithDefaultDisk(): void
    {
        Storage::fake();

        $image = new Image($this->fakeImageContents(100, 100));
        $image->toWebp()->store('avatars');

        $files = Storage::files('avatars');

        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.webp', $files[0]);
    }

    public function testStoreWithNoArguments(): void
    {
        Storage::fake();

        $image = new Image($this->fakeImageContents(100, 100));
        $image->store();

        $files = Storage::allFiles();

        $this->assertCount(1, $files);
    }

    public function testStoreAsWithPathAndNameOnly(): void
    {
        Storage::fake();

        $image = new Image($this->fakeImageContents(100, 100));
        $image->storeAs('avatars', 'photo.jpg');

        Storage::assertExists('avatars/photo.jpg');
    }

    public function testStoreAsWithNameOnlyNoOptions(): void
    {
        Storage::fake();

        $image = new Image($this->fakeImageContents(100, 100));
        $image->storeAs('photo.jpg');

        Storage::assertExists('photo.jpg');
    }

    public function testStorePubliclyWithDefaultDisk(): void
    {
        Storage::fake();

        $image = new Image($this->fakeImageContents(100, 100));
        $image->storePublicly('avatars');

        $files = Storage::files('avatars');

        $this->assertCount(1, $files);
    }

    public function testStorePubliclyAsWithNameOnly(): void
    {
        Storage::fake();

        $image = new Image($this->fakeImageContents(100, 100));
        $image->storePubliclyAs('avatar.jpg');

        Storage::assertExists('avatar.jpg');
    }

    public function testStorePubliclyAsWithPathAndName(): void
    {
        Storage::fake();

        $image = new Image($this->fakeImageContents(100, 100));
        $image->storePubliclyAs('avatars', 'photo.jpg');

        Storage::assertExists('avatars/photo.jpg');
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
}
