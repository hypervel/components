<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai;

use Hypervel\Ai\Image;
use Hypervel\Tests\Ai\TestCase;
use Hypervel\Tests\Integration\Ai\Fixtures\Providers;
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
    }
}
