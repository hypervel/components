<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Exception;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Image;
use Hypervel\Ai\Jobs\GenerateImage;
use Hypervel\Ai\Prompts\ImagePrompt;
use Hypervel\Ai\Prompts\QueuedImagePrompt;
use Hypervel\Ai\Responses\Data\GeneratedImage;
use Hypervel\Ai\Responses\Data\ImageUsage;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\ImageResponse;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Storage;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use RuntimeException;

class ImageFakeTest extends TestCase
{
    /**
     * Clear serialized callback results.
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['imageResponse']);

        parent::tearDown();
    }

    public function testImageRejectsEmptyPrompt(): void
    {
        Image::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A prompt is required to generate an image.');
        Image::of('')->generate();
    }

    public function testImageRejectsWhitespaceOnlyPrompt(): void
    {
        Image::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A prompt is required to generate an image.');
        Image::of('   ')->generate();
    }

    public function testImagesCanBeFaked(): void
    {
        Image::fake([
            base64_encode('first-image'),
            fn (ImagePrompt $prompt): string => base64_encode('second-image-' . $prompt->prompt),
            new ImageResponse(
                new Collection([new GeneratedImage(base64_encode('third-image'))]),
                new ImageUsage,
                new Meta,
            ),
        ]);

        $response = Image::of('First prompt')->generate();
        $this->assertSame(base64_encode('first-image'), $response->firstImage()->image);

        $response = Image::of('Second prompt')->generate();
        $this->assertSame(base64_encode('second-image-Second prompt'), $response->firstImage()->image);

        $response = Image::of('Third prompt')->generate();
        $this->assertSame(base64_encode('third-image'), $response->firstImage()->image);

        // Assertion tests...
        Image::assertGenerated(fn (ImagePrompt $prompt): bool => $prompt->prompt === 'First prompt');
        Image::assertNotGenerated(fn (ImagePrompt $prompt): bool => $prompt->prompt === 'Missing prompt');

        Image::assertGenerated(fn (ImagePrompt $prompt): bool => $prompt->prompt === 'First prompt');
    }

    public function testCanAssertNoImagesWereGenerated(): void
    {
        Image::fake();

        Image::assertNothingGenerated();
    }

    public function testImagesCanBeFakedWithNoPredefinedResponses(): void
    {
        Image::fake();

        $response = Image::of('First prompt')->generate();
        $this->assertSame(base64_encode('fake-image-content'), $response->firstImage()->image);

        $response = Image::of('Second prompt')->generate();
        $this->assertSame(base64_encode('fake-image-content'), $response->firstImage()->image);
    }

    public function testImagesCanBeFakedWithASingleClosureThatIsInvokedForEveryGeneration(): void
    {
        Image::fake(fn (ImagePrompt $prompt): string => base64_encode('image-for-' . $prompt->prompt));

        $response = Image::of('First prompt')->generate();
        $this->assertSame(base64_encode('image-for-First prompt'), $response->firstImage()->image);

        $response = Image::of('Second prompt')->generate();
        $this->assertSame(base64_encode('image-for-Second prompt'), $response->firstImage()->image);
    }

    public function testImagesCanPreventStrayGenerations(): void
    {
        Image::fake()->preventStrayImages();

        $this->expectException(RuntimeException::class);
        Image::of('First prompt')->generate();
    }

    public function testFakeClosuresCanThrowExceptions(): void
    {
        Image::fake(function (): void {
            throw new Exception('Something went wrong');
        });

        $this->expectException(Exception::class);
        Image::of('Test prompt')->generate();
    }

    public function testImageSizeAndQualityAreRecorded(): void
    {
        Image::fake();

        Image::of('A sunset')->square()->quality('high')->generate();

        Image::assertGenerated(fn (ImagePrompt $prompt): bool => $prompt->prompt === 'A sunset'
            && $prompt->size === '1:1'
            && $prompt->quality === 'high');
    }

    public function testImagePortraitAspectRatioIsRecorded(): void
    {
        Image::fake();

        Image::of('A sunset')->portrait()->generate();

        Image::assertGenerated(fn (ImagePrompt $prompt): bool => $prompt->prompt === 'A sunset'
            && $prompt->size === '2:3');
    }

    public function testImageCustomSizeIsRecorded(): void
    {
        Image::fake();

        Image::of('A sunset')->size('16:9')->generate();

        Image::assertGenerated(fn (ImagePrompt $prompt): bool => $prompt->prompt === 'A sunset'
            && $prompt->size === '16:9');
    }

    public function testImageIsStoredUnderARandomNameDerivedFromItsMimeType(): void
    {
        Storage::fake('images');

        Image::fake([
            new ImageResponse(
                new Collection([new GeneratedImage(base64_encode('raw-bytes'), 'image/jpeg')]),
                new ImageUsage,
                new Meta,
            ),
        ]);

        $path = Image::of('A sunset')->generate()->store('generated', 'images');

        $this->assertStringStartsWith('generated/', $path);
        $this->assertStringEndsWith('.jpg', $path);
        $this->assertSame('raw-bytes', Storage::disk('images')->get($path));
    }

    public function testImageCanBeStoredUnderAnExplicitPathAndName(): void
    {
        Storage::fake('images');

        Image::fake([base64_encode('raw-bytes')]);

        $response = Image::of('A sunset')->generate();

        $this->assertSame('generated/sunset.png', $response->storeAs('generated', 'sunset.png', 'images'));
        $this->assertSame('sunset.png', $response->storeAs('sunset.png', null, 'images'));
        $this->assertSame('raw-bytes', Storage::disk('images')->get('generated/sunset.png'));
        $this->assertSame('raw-bytes', Storage::disk('images')->get('sunset.png'));
    }

    public function testStoringAnImagePubliclyPassesPublicVisibilityToTheDisk(): void
    {
        Storage::fake('images', ['visibility' => 'private']);

        Image::fake([base64_encode('raw-bytes')]);

        $response = Image::of('A sunset')->generate();

        $private = $response->store('private', 'images');
        $public = $response->storePublicly('public', 'images');
        $named = $response->storePubliclyAs('sunset.png', null, 'images');

        $this->assertSame('private', Storage::disk('images')->getVisibility($private));
        $this->assertSame('public', Storage::disk('images')->getVisibility($public));
        $this->assertSame('public', Storage::disk('images')->getVisibility($named));
        $this->assertSame('sunset.png', $named);
    }

    public function testQueuedImagesCanBeFaked(): void
    {
        Image::fake();

        Image::of('First prompt')->queue();

        Image::assertQueued(fn (QueuedImagePrompt $prompt): bool => $prompt->prompt === 'First prompt');
        Image::assertNotQueued(fn (QueuedImagePrompt $prompt): bool => $prompt->contains('Second prompt'));

        Image::assertQueued(fn (QueuedImagePrompt $prompt): bool => $prompt->prompt === 'First prompt');

        Image::assertNotQueued(fn (QueuedImagePrompt $prompt): bool => $prompt->prompt === 'Second prompt');
    }

    public function testQueuedImagesCanBeFakedAndThenCallbackIsExecuted(): void
    {
        Image::fake([base64_encode('image')]);

        $GLOBALS['imageResponse'] = null;

        Image::of('First prompt')->queue()->then(static function (ImageResponse $response): void {
            $GLOBALS['imageResponse'] = $response;
        });

        Image::assertQueued(fn (QueuedImagePrompt $prompt): bool => $prompt->prompt === 'First prompt');

        $this->assertInstanceOf(ImageResponse::class, $GLOBALS['imageResponse']);
        $this->assertSame(base64_encode('image'), $GLOBALS['imageResponse']->firstImage()->image);
    }

    public function testQueuedImagesCanBeFakedAndThenCallbackIsNotExecutedIfQueueIsFaked(): void
    {
        Image::fake([base64_encode('image')]);
        Queue::fake();

        $GLOBALS['imageResponse'] = null;

        Image::of('First prompt')->queue()->then(static function (ImageResponse $response): void {
            $GLOBALS['imageResponse'] = $response;
        });

        Image::assertQueued(fn (QueuedImagePrompt $prompt): bool => $prompt->prompt === 'First prompt');

        $this->assertNull($GLOBALS['imageResponse']);

        Queue::assertPushed(GenerateImage::class);
    }

    public function testCanAssertNoImagesWereQueued(): void
    {
        Image::fake();

        Image::assertNothingQueued();
    }

    public function testGenerateAcceptsAiProviderEnum(): void
    {
        Image::fake();

        Image::of('Enum image')->generate(provider: Lab::Gemini);

        Image::assertGenerated(fn (ImagePrompt $prompt): bool => $prompt->prompt === 'Enum image');
    }

    public function testQueuedImageAcceptsAiProviderEnum(): void
    {
        Image::fake();

        Image::of('Queued enum image')->queue(provider: Lab::OpenAI);

        Image::assertQueued(fn (QueuedImagePrompt $prompt): bool => $prompt->prompt === 'Queued enum image'
            && $prompt->provider === Lab::OpenAI);
    }

    public function testQueuedImageSizeAndQualityAreRecorded(): void
    {
        Image::fake();

        Image::of('A sunset')->landscape()->quality('low')->queue();

        Image::assertQueued(fn (QueuedImagePrompt $prompt): bool => $prompt->prompt === 'A sunset'
            && $prompt->size === '3:2'
            && $prompt->quality === 'low');
    }

    public function testImageTimeoutIsRecordedForGeneratedAndQueuedPrompts(): void
    {
        Image::fake();

        Image::of('First prompt')->timeout(45)->generate();
        Image::of('Second prompt')->timeout(90)->queue();

        Image::assertGenerated(fn (ImagePrompt $prompt): bool => $prompt->timeout === 45);
        Image::assertQueued(fn (QueuedImagePrompt $prompt): bool => $prompt->timeout === 90);
    }

    public function testThrowingSequenceEntryIsConsumed(): void
    {
        $exception = new RuntimeException('Image generation failed.');
        Image::fake([fn (): never => throw $exception, base64_encode('second-image')]);

        try {
            Image::of('First')->generate();
            $this->fail('The first response must throw.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(base64_encode('second-image'), Image::of('Second')->generate()->firstImage()->image);
    }
}
