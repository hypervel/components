<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\AzureOpenAi;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Exceptions\ProviderOverloadedException;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Files\LocalDocument;
use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Image;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\RequestException;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class ImageGenerationTest extends TestCase
{
    /**
     * Configure the Azure OpenAI endpoint.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('ai.providers.azure', [
            ...$config->get('ai.providers.azure'),
            'key' => 'test-key',
            'url' => 'https://test-resource.openai.azure.com',
        ]);
    }

    /**
     * Build an image response.
     */
    private function fakeAzureImageResponse(): PromiseInterface
    {
        return Http::response([
            'data' => [[
                'b64_json' => base64_encode('fake-image'),
            ]],
        ]);
    }

    public function testImageRequestUsesCorrectDeploymentAndUrl(): void
    {
        Http::fake([
            '*' => $this->fakeAzureImageResponse(),
        ]);

        Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://test-resource.openai.azure.com/openai/v1/images/generations'
                && $body['model'] === 'gpt-image-1';
        });
    }

    public function testImageRequestDoesNotIncludeQualityWhenNotSpecified(): void
    {
        Http::fake([
            '*' => $this->fakeAzureImageResponse(),
        ]);

        Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('quality', $body);
        });
    }

    public function testImageRequestIncludesQualityWhenExplicitlySpecified(): void
    {
        Http::fake([
            '*' => $this->fakeAzureImageResponse(),
        ]);

        Image::of('A red apple')->quality('high')->generate(provider: 'azure', model: 'gpt-image-1');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['quality'] === 'high';
        });
    }

    public function testImageRequestIncludesSizeWhenSpecified(): void
    {
        Http::fake([
            '*' => $this->fakeAzureImageResponse(),
        ]);

        Image::of('A red apple')->square()->generate(provider: 'azure', model: 'gpt-image-1');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['size'] === '1024x1024';
        });
    }

    public function testImageRequestDoesNotIncludeSizeWhenNotSpecified(): void
    {
        Http::fake([
            '*' => $this->fakeAzureImageResponse(),
        ]);

        Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('size', $body);
        });
    }

    public function testImageRequestAlwaysIncludesModerationLow(): void
    {
        Http::fake([
            '*' => $this->fakeAzureImageResponse(),
        ]);

        Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ($body['moderation'] ?? null) === 'low';
        });
    }

    #[DataProvider('editAttachments')]
    public function testImageGenerationSendsAttachmentsToTheEditEndpoint(Closure $attachment): void
    {
        Http::fake([
            '*' => $this->fakeAzureImageResponse(),
        ]);

        Image::of('Add a leaf to the apple')
            ->attachments([$attachment()])
            ->quality('high')->square()->withProviderOptions(['n' => 2])
            ->generate(provider: 'azure', model: 'gpt-image-1');

        Http::assertSent(function (Request $request): bool {
            $fields = array_column($request->data(), 'contents', 'name');

            return $request->url() === 'https://test-resource.openai.azure.com/openai/v1/images/edits'
                && $request->hasFile('image[]', file_get_contents(__DIR__ . '/../../../Fixtures/Images/red.png'))
                && $fields['prompt'] === 'Add a leaf to the apple'
                && $fields['model'] === 'gpt-image-1'
                && $fields['quality'] === 'high'
                && $fields['size'] === '1024x1024'
                && $fields['n'] === 2
                && ! array_key_exists('moderation', $fields)
                && ! array_key_exists('response_format', $fields);
        });
    }

    /**
     * Provide stored-content and uploaded image attachments.
     */
    public static function editAttachments(): array
    {
        $path = __DIR__ . '/../../../Fixtures/Images/red.png';

        return [
            'local' => [fn (): LocalImage => new LocalImage($path)],
            'upload' => [fn (): UploadedFile => new UploadedFile($path, 'red.png', 'image/png', null, true)],
        ];
    }

    public function testImageEditsRejectNonImageAttachments(): void
    {
        Http::fake();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported image attachment type');

        Image::of('Edit')->attachments([new LocalDocument('/tmp/document.pdf')])->generate(provider: 'azure');
    }

    public function testImageGenerationRequestOmitsResponseFormat(): void
    {
        Http::fake([
            '*' => $this->fakeAzureImageResponse(),
        ]);

        Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('response_format', $body);
        });
    }

    public function testImageResponseIncludesUsageTokens(): void
    {
        Http::fake([
            '*' => Http::response([
                'output_format' => 'jpeg',
                'data' => [[
                    'b64_json' => base64_encode('fake-image'),
                ]],
                'usage' => [
                    'input_tokens' => 41,
                    'output_tokens' => 1024,
                    'input_tokens_details' => [
                        'text_tokens' => 41,
                        'image_tokens' => 0,
                    ],
                ],
            ]),
        ]);

        $response = Image::of('A red apple')->withProviderOptions(['output_format' => 'jpeg'])->generate(provider: 'azure', model: 'gpt-image-1');

        $this->assertSame(41, $response->usage->inputTokens);
        $this->assertSame(1024, $response->usage->outputTokens);
        $this->assertSame('image/jpeg', $response->images->first()->mime);
    }

    public function testImageResponseReportsCachedTokensWithinTheInputTokens(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [[
                    'b64_json' => base64_encode('fake-image'),
                ]],
                'usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 1024,
                    'input_tokens_details' => [
                        'cached_tokens' => 30,
                        'text_tokens' => 70,
                    ],
                ],
            ]),
        ]);

        $response = Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');

        $this->assertSame(100, $response->usage->inputTokens);
        $this->assertSame(30, $response->usage->cacheReadInputTokens);
        $this->assertSame(1024, $response->usage->outputTokens);
    }

    public function testImageResponseDefaultsToZeroUsageWhenNotReturned(): void
    {
        Http::fake([
            '*' => $this->fakeAzureImageResponse(),
        ]);

        $response = Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');

        $this->assertSame(0, $response->usage->inputTokens);
        $this->assertSame(0, $response->usage->outputTokens);
    }

    public function testDefaultImageModelFallsBackToGptImage25Flare(): void
    {
        config(['ai.providers.azure.image_deployment' => null]);

        Http::fake([
            '*' => $this->fakeAzureImageResponse(),
        ]);

        Image::of('A red apple')->generate(provider: 'azure');

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'gpt-image-2.5-flare';
        });
    }

    public function testImageRateLimitResponseThrowsRateLimitedException(): void
    {
        $this->expectException(RateLimitedException::class);
        Http::fake([
            'test-resource.openai.azure.com/*' => Http::response([
                'error' => [
                    'code' => '429',
                    'message' => 'Requests to the Generations_Create Operation under Azure OpenAI API have exceeded call rate limit of your current OpenAI S0 pricing tier.',
                ],
            ], 429),
        ]);

        Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');
    }

    public function testImageOverloadedResponseThrowsProviderOverloadedException(): void
    {
        $this->expectException(ProviderOverloadedException::class);
        Http::fake([
            'test-resource.openai.azure.com/*' => Http::response([
                'error' => [
                    'code' => 'ServiceUnavailable',
                    'message' => 'The server is currently overloaded. Please try again later.',
                ],
            ], 503),
        ]);

        Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');
    }

    public function testImageHttpErrorResponseThrowsRequestException(): void
    {
        $this->expectException(RequestException::class);
        Http::fake([
            'test-resource.openai.azure.com/*' => Http::response([
                'error' => [
                    'code' => 'contentFilter',
                    'message' => 'Your task failed as a result of our safety system. Image generations failure reason: The generated image was filtered as a result of our content policy.',
                    'innererror' => [
                        'code' => 'ResponsibleAIPolicyViolation',
                        'content_filter_result' => [
                            'sexual' => ['filtered' => false, 'severity' => 'safe'],
                            'violence' => ['filtered' => true, 'severity' => 'medium'],
                        ],
                    ],
                ],
            ], 400),
        ]);

        Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');
    }

    public function testImageResponseReportsTheImageTokenDetailsReturnedByGptImage(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [[
                    'b64_json' => base64_encode('fake-image'),
                ]],
                'usage' => [
                    'input_tokens' => 187,
                    'output_tokens' => 1481,
                    'input_tokens_details' => [
                        'image_tokens' => 146,
                    ],
                    'output_tokens_details' => [
                        'image_tokens' => 1272,
                    ],
                ],
            ]),
        ]);

        $response = Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');

        $this->assertSame(146, $response->usage->imageInputTokens);
        $this->assertSame(1272, $response->usage->imageOutputTokens);
    }

    public function testImageResponseLeavesTheImageTokenDetailsNullWhenTheApiVersionOmitsThem(): void
    {
        Http::fake([
            '*' => $this->fakeAzureImageResponse(),
        ]);

        $response = Image::of('A red apple')->generate(provider: 'azure', model: 'gpt-image-1');

        $this->assertNull($response->usage->imageInputTokens);
        $this->assertNull($response->usage->imageOutputTokens);
    }
}
