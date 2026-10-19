<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai;

use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Image;
use Hypervel\Tests\Ai\TestCase;
use Hypervel\Tests\Integration\Ai\Fixtures\Providers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;

use function Hypervel\Tests\Ai\Fixtures\requiresApiKey;

require_once __DIR__ . '/../../Ai/Fixtures/helpers.php';

class ImageTest extends TestCase
{
    #[DataProviderExternal(Providers::class, 'images')]
    public function testImagesCanBeGenerated(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        $response = Image::of('Donut sitting on a kitchen counter.')
            ->generate(provider: $provider, model: $model);

        $this->assertSame($provider, $response->meta->provider);
        $this->assertNotEmpty($response->images);
        $this->assertNotEmpty(base64_decode($response->images->first()->image, true));
    }

    #[DataProviderExternal(Providers::class, 'images')]
    public function testImagesCanBeGeneratedWithSquareSize(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        $response = Image::of('Donut sitting on a kitchen counter.')
            ->square()
            ->generate(provider: $provider, model: $model);

        $this->assertSame($provider, $response->meta->provider);
        $this->assertNotEmpty($response->images);
        $this->assertNotEmpty(base64_decode($response->images->first()->image, true));
    }

    #[DataProvider('openRouterImageModels')]
    public function testOpenrouterImagesCanBeEditedFromAReferenceAtALandscapeSizeAndQuality(string $provider, string $apiKey, string $model): void
    {
        requiresApiKey($apiKey);

        $response = Image::of('Turn this into a watercolor painting.')
            ->attachments([new LocalImage(__DIR__ . '/../../Ai/Fixtures/Images/red.png')])
            ->landscape()
            ->quality('low')
            ->generate(provider: $provider, model: $model);

        $this->assertNotEmpty($response->images);
    }

    /**
     * Provide OpenRouter image models that accept reference images.
     */
    public static function openRouterImageModels(): array
    {
        return [
            'gemini' => ['openrouter', 'OPENROUTER_API_KEY', 'google/gemini-3.1-flash-image'],
            'gpt-image' => ['openrouter', 'OPENROUTER_API_KEY', 'openai/gpt-image-1-mini'],
            'flux' => ['openrouter', 'OPENROUTER_API_KEY', 'black-forest-labs/flux-3-image'],
            'seedream' => ['openrouter', 'OPENROUTER_API_KEY', 'bytedance-seed/seedream-4.5'],
            'grok' => ['openrouter', 'OPENROUTER_API_KEY', 'x-ai/grok-imagine-image-2.0'],
        ];
    }
}
