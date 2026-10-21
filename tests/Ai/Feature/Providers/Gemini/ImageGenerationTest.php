<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Image;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Tests\Ai\Fixtures\sentRequest;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

/**
 * Create an image interaction response.
 */
function fakeGeminiImageInteraction(array $steps, array $usage = []): PromiseInterface
{
    return Http::response(array_filter([
        'id' => 'int_image',
        'status' => 'completed',
        'steps' => $steps,
        'usage' => $usage ?: null,
    ]));
}

/**
 * Create an image content block.
 */
function geminiImageBlock(string $mimeType = 'image/png'): array
{
    return ['type' => 'image', 'mime_type' => $mimeType, 'data' => base64_encode('fake-image')];
}

/**
 * Create a single-image response.
 */
function fakeGeminiImageResponse(string $mimeType = 'image/png'): PromiseInterface
{
    return fakeGeminiImageInteraction([[
        'type' => 'model_output',
        'content' => [geminiImageBlock($mimeType)],
    ]]);
}

class ImageGenerationTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.gemini.key', 'test-key');
    }

    public function testImageRequestPostsThePromptToTheInteractionsEndpoint(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
        ]);

        Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        $this->assertStringEndsWith('/interactions', sentRequest()->url());
        $this->assertSame('gemini-3.1-flash-image-preview', sentRequest()->data()['model']);
        $this->assertFalse(sentRequest()->data()['store']);
        $this->assertSame('text', sentRequest()->data()['input'][0]['type']);
        $this->assertSame('A red apple', sentRequest()->data()['input'][0]['text']);
    }

    public function testImageRequestAsksForAnImageResponseFormat(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
        ]);

        Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        $this->assertSame('image', sentRequest()->data()['response_format']['type']);
    }

    public function testImageRequestIncludesDefaultImageSizeWhenQualityNotSpecified(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
        ]);

        Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        $this->assertSame('1K', sentRequest()->data()['response_format']['image_size']);
    }

    #[DataProvider('imageSizes')]
    public function testImageRequestMapsQualityToImageSize(string $quality, string $expectedSize): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
        ]);

        Image::of('A red apple')->quality($quality)->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        $this->assertSame($expectedSize, sentRequest()->data()['response_format']['image_size']);
    }

    /**
     * Provide the image size for each quality.
     */
    public static function imageSizes(): array
    {
        return [
            'low maps to 1K' => ['low', '1K'],
            'medium maps to 2K' => ['medium', '2K'],
            'high maps to 4K' => ['high', '4K'],
        ];
    }

    public function testImageRequestMapsSizeToAspectRatio(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
        ]);

        Image::of('A red apple')->square()->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        $this->assertSame('1:1', sentRequest()->data()['response_format']['aspect_ratio']);
    }

    public function testImageRequestDoesNotIncludeAspectRatioWhenSizeNotSpecified(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
        ]);

        Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        $this->assertArrayNotHasKey('aspect_ratio', sentRequest()->data()['response_format']);
    }

    public function testImageAttachmentIsAppendedToTheInputBlocks(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
        ]);

        $attachment = new Base64Image(base64_encode('ref-image'), 'image/jpeg');

        Image::of('A red apple')->attachments([$attachment])->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        $this->assertCount(2, sentRequest()->data()['input']);
        $this->assertSame('A red apple', sentRequest()->data()['input'][0]['text']);
        $this->assertSame('image', sentRequest()->data()['input'][1]['type']);
        $this->assertSame('image/jpeg', sentRequest()->data()['input'][1]['mime_type']);
    }

    public function testOnlyImageBlocksAreReturnedWhenTheResponseMixesTextAndImages(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiImageInteraction([[
                'type' => 'model_output',
                'content' => [
                    ['type' => 'text', 'text' => 'Here is your image:'],
                    geminiImageBlock(),
                ],
            ]]),
        ]);

        $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        $this->assertCount(1, $response->images);
        $this->assertSame('image/png', $response->images->first()->mime);
    }

    public function testFirstImageWorksWhenTheResponseLeadsWithATextBlock(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiImageInteraction([[
                'type' => 'model_output',
                'content' => [
                    ['type' => 'text', 'text' => 'Here is your image:'],
                    geminiImageBlock(),
                ],
            ]]),
        ]);

        $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        $this->assertSame('image/png', $response->firstImage()->mime);
        $this->assertSame(base64_encode('fake-image'), $response->firstImage()->image);
        $this->assertSame('fake-image', (string) $response);
    }

    public function testImagesInterleavedAcrossSeveralStepsAreAllCollected(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiImageInteraction([
                ['type' => 'model_output', 'content' => [geminiImageBlock('image/png')]],
                ['type' => 'model_output', 'content' => [geminiImageBlock('image/jpeg')]],
            ]),
        ]);

        $response = Image::of('Two apples')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        $this->assertCount(2, $response->images);
        $this->assertSame('image/jpeg', $response->images->last()->mime);
    }

    public function testImageResponseIsCorrectlyParsed(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse('image/png'),
        ]);

        $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        $this->assertCount(1, $response->images);
        $this->assertSame(base64_encode('fake-image'), $response->images->first()->image);
        $this->assertSame('image/png', $response->images->first()->mime);
        $this->assertSame('gemini', $response->meta->provider);
    }

    public function testRequestSendsXGoogApiKeyHeader(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
        ]);

        Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-goog-api-key', 'test-key'));
    }

    public function testImageResponseIncludesUsageWhenReturned(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiImageInteraction(
                [['type' => 'model_output', 'content' => [geminiImageBlock()]]],
                ['total_input_tokens' => 12, 'total_output_tokens' => 1290, 'total_tokens' => 1302],
            ),
        ]);

        $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        $this->assertSame(12, $response->usage->inputTokens);
        $this->assertSame(1290, $response->usage->outputTokens);
    }

    public function testImageResponseDefaultsToZeroUsageWhenUsageIsAbsent(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
        ]);

        $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        $this->assertSame(0, $response->usage->inputTokens);
        $this->assertSame(0, $response->usage->outputTokens);
    }

    public function testImageRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 429,
                    'message' => 'Resource has been exhausted',
                    'status' => 'RESOURCE_EXHAUSTED',
                ],
            ], 429),
        ]);

        Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');
    }

    public function testImageOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 503,
                    'message' => 'The model is overloaded. Please try again later.',
                    'status' => 'UNAVAILABLE',
                ],
            ], 503),
        ]);

        Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');
    }

    public function testImageHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 400,
                    'message' => 'Invalid value at prompt',
                    'status' => 'INVALID_ARGUMENT',
                ],
            ], 400),
        ]);

        Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');
    }

    #[DataProvider('blockedImageResponses')]
    public function testImageResponseIsEmptyWhenTheInteractionReturnsNoSteps(string $code, string $message): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'id' => 'int_blocked',
                'status' => 'failed',
                'steps' => [],
                'errors' => [['code' => $code, 'message' => $message]],
            ]),
        ]);

        $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        $this->assertCount(0, $response->images);
    }

    /**
     * Provide withheld-image error codes.
     */
    public static function blockedImageResponses(): array
    {
        return [
            'safety' => ['BLOCKED_SAFETY', 'Blocked for safety reasons.'],
            'other restriction' => ['image_other', 'Output withheld.'],
        ];
    }

    public function testImageResponseIsEmptyWhenTheStepCarriesNoContent(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiImageInteraction([['type' => 'model_output']]),
        ]);

        $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        $this->assertCount(0, $response->images);
    }

    public function testAResponseFormatProviderOptionIsMergedBeneathTheCoreFormat(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => fakeGeminiImageResponse()]);

        Image::of('A red apple')
            ->withProviderOptions(['response_format' => ['mime_type' => 'image/jpeg', 'type' => 'text']])
            ->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        $this->assertSame('image/jpeg', sentRequest()->data()['response_format']['mime_type']);
        $this->assertSame('image', sentRequest()->data()['response_format']['type']);
    }

    public function testImageResponseReportsTheImageModalityTokenCounts(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiImageInteraction(
                [['type' => 'model_output', 'content' => [geminiImageBlock()]]],
                [
                    'total_input_tokens' => 270,
                    'total_output_tokens' => 1290,
                    'total_tokens' => 1560,
                    'input_tokens_by_modality' => [
                        ['modality' => 'TEXT', 'tokens' => 12],
                        ['modality' => 'IMAGE', 'tokens' => 258],
                    ],
                    'output_tokens_by_modality' => [
                        ['modality' => 'IMAGE', 'tokens' => 1290],
                    ],
                ],
            ),
        ]);

        $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        $this->assertSame(258, $response->usage->imageInputTokens);
        $this->assertSame(1290, $response->usage->imageOutputTokens);
    }

    public function testImageResponseLeavesTheImageModalityCountsNullWhenNoDetailsAreReturned(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => fakeGeminiImageResponse(),
        ]);

        $response = Image::of('A red apple')->generate(provider: 'gemini', model: 'gemini-3.1-flash-image-preview');

        $this->assertNull($response->usage->imageInputTokens);
        $this->assertNull($response->usage->imageOutputTokens);
    }

    public function testFailedInteractionPreservesTheProviderError(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'failed',
            'errors' => [['code' => 'internal', 'message' => 'The model stopped responding.']],
        ])]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('Gemini Error: [internal] The model stopped responding.');

        Image::of('A red apple')->generate(provider: 'gemini');
    }
}
