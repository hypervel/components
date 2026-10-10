<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\Bedrock;

use Hypervel\Ai\Gateway\Bedrock\BedrockImageGateway;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

function imageGateway(): object
{
    return new class extends BedrockImageGateway {
        /**
         * Prepare the model request body.
         */
        public function callPrepareBody(string $model, string $prompt, ?string $size, ?string $quality): array
        {
            return $this->prepareImageRequestBody($model, $prompt, $size, [
                'quality' => $quality ?? 'standard',
                'size' => $size ?? '1:1',
            ]);
        }

        /**
         * Parse generated image results.
         */
        public function callParseResponse(string $model, array $result): Collection
        {
            return $this->parseImageResponse($model, $result);
        }

        /**
         * Parse the requested aspect ratio.
         */
        public function callParseSize(?string $size): array
        {
            return $this->parseSize($size);
        }
    };
}

class BedrockImageGatewayTest extends TestCase
{
    public function testParseSizeConvertsPortraitDimensions(): void
    {
        $this->assertSame([768, 1152], imageGateway()->callParseSize('2:3'));
    }

    public function testParseSizeConvertsLandscapeDimensions(): void
    {
        $this->assertSame([1152, 768], imageGateway()->callParseSize('3:2'));
    }

    public function testParseSizeDefaultsToSquareDimensions(): void
    {
        $this->assertSame([1024, 1024], imageGateway()->callParseSize(null));
        $this->assertSame([1024, 1024], imageGateway()->callParseSize('1:1'));
    }

    #[DataProvider('prompts')]
    public function testStabilityBodyUsesPromptAndAspectRatio(string $prompt): void
    {
        $body = imageGateway()->callPrepareBody('stability.sd3-5-large-v1:0', $prompt, '2:3', 'standard');

        $this->assertSame(['prompt' => $prompt, 'aspect_ratio' => '2:3', 'output_format' => 'png'], $body);
    }

    /**
     * Provide ordinary and numeric image prompts.
     */
    public static function prompts(): array
    {
        return [['a cat'], ['0']];
    }

    public function testStabilityBodyOmitsAspectRatioWhenSizeIsNull(): void
    {
        $body = imageGateway()->callPrepareBody('stability.stable-image-ultra-v1:0', 'a cat', null, 'standard');

        $this->assertSame(['prompt' => 'a cat', 'output_format' => 'png'], $body);
    }

    public function testTitanImageBodyUsesTextToImageParams(): void
    {
        $body = imageGateway()->callPrepareBody('amazon.titan-image-generator-v1', 'a dog', '2:3', 'premium');

        $this->assertSame([
            'taskType' => 'TEXT_IMAGE',
            'textToImageParams' => ['text' => 'a dog'],
            'imageGenerationConfig' => [
                'numberOfImages' => 1,
                'quality' => 'premium',
                'height' => 1152,
                'width' => 768,
                'cfgScale' => 7.0,
            ],
        ], $body);
    }

    public function testTitanImageBodyDefaultsQualityToStandard(): void
    {
        $body = imageGateway()->callPrepareBody('amazon.titan-image-generator-v1', 'a dog', null, 'standard');

        $this->assertSame('standard', $body['imageGenerationConfig']['quality']);
    }

    public function testNovaCanvasBodyUsesTextToImageParams(): void
    {
        $body = imageGateway()->callPrepareBody('amazon.nova-canvas-v1:0', 'a bird', '3:2', 'standard');

        $this->assertSame([
            'taskType' => 'TEXT_IMAGE',
            'textToImageParams' => ['text' => 'a bird'],
            'imageGenerationConfig' => [
                'numberOfImages' => 1,
                'quality' => 'standard',
                'width' => 1152,
                'height' => 768,
            ],
        ], $body);
    }

    public function testUnknownModelFamilyFallsBackToPlainPromptBody(): void
    {
        $body = imageGateway()->callPrepareBody('unknown-model', 'something', '1:1', 'high');

        $this->assertSame(['prompt' => 'something'], $body);
    }

    public function testStabilityResponseIsParsedFromImagesArray(): void
    {
        $images = imageGateway()->callParseResponse('stability.sd3-5-large-v1:0', [
            'images' => ['sd-image-1', 'sd-image-2'],
        ]);

        $this->assertCount(2, $images);
        $this->assertSame('sd-image-1', $images[0]->image);
        $this->assertSame('image/png', $images[0]->mime);
        $this->assertSame('sd-image-2', $images[1]->image);
    }

    public function testTitanResponseIsParsedFromImagesArray(): void
    {
        $images = imageGateway()->callParseResponse('amazon.titan-image-generator-v1', [
            'images' => ['titan-image-1', 'titan-image-2'],
        ]);

        $this->assertCount(2, $images);
        $this->assertSame('titan-image-1', $images[0]->image);
        $this->assertSame('image/png', $images[0]->mime);
    }

    public function testNovaCanvasResponseIsParsedFromImagesArray(): void
    {
        $images = imageGateway()->callParseResponse('amazon.nova-canvas-v1:0', ['images' => ['nova-image-1']]);

        $this->assertCount(1, $images);
        $this->assertSame('nova-image-1', $images[0]->image);
    }

    public function testUnknownModelFamilyReturnsEmptyCollection(): void
    {
        $images = imageGateway()->callParseResponse('unknown-model', [
            'artifacts' => [['base64' => 'ignored']],
            'images' => ['ignored'],
        ]);

        $this->assertCount(0, $images);
    }

    public function testMissingImagesKeyYieldsEmptyCollection(): void
    {
        $this->assertCount(0, imageGateway()->callParseResponse('stability.sd3-5-large-v1:0', []));
        $this->assertCount(0, imageGateway()->callParseResponse('amazon.titan-image-v1', []));
        $this->assertCount(0, imageGateway()->callParseResponse('amazon.nova-canvas-v1', []));
    }
}
