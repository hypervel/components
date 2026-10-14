<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai;

use Hypervel\Ai\Classification;
use Hypervel\Ai\Classification\Boolean;
use Hypervel\Ai\Classification\Choice;
use Hypervel\Ai\Classification\Score;
use Hypervel\Ai\Events\Classified;
use Hypervel\Ai\Events\Classifying;
use Hypervel\Ai\Files\Image;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Ai\TestCase;
use Hypervel\Tests\Integration\Ai\Fixtures\Providers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;

use function Hypervel\Tests\Ai\Fixtures\requiresApiKey;

require_once __DIR__ . '/../../Ai/Fixtures/helpers.php';

class ClassificationTest extends TestCase
{
    #[DataProviderExternal(Providers::class, 'classification')]
    public function testStatesCanBeClassified(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        Event::fake();

        $response = Classification::of("I've been trying to connect my Stripe account for 3 days and it keeps failing. I'm losing sales. Please help ASAP.")
            ->questions([
                'is_urgent' => new Boolean('Does this message convey urgency?'),
                'department' => new Choice('Which team should handle this?', [
                    'billing' => 'Payments, invoicing, refunds',
                    'technical' => 'Bugs, outages, integrations',
                    'sales' => 'Pricing, plans, upgrades',
                ]),
                'frustration' => new Score('How frustrated is the customer?', [
                    'Calm, stating facts', 'Frustrated but civil', 'Very angry',
                ]),
            ])->classify(provider: $provider);

        $this->assertTrue($response['is_urgent']->isTrue());
        $this->assertSame('technical', $response['department']->choice);
        $this->assertSame(1.0, round(array_sum($response['department']->probabilities), 1));
        $this->assertGreaterThan(0.5, $response['frustration']->score);
        $this->assertGreaterThan(0, $response->usage->inputTokens);
        $this->assertSame($provider, $response->meta->provider);

        Event::assertDispatched(Classifying::class);
        Event::assertDispatched(Classified::class);
    }

    #[DataProvider('imageProviders')]
    public function testImagesCanBeClassifiedAlongsideTheState(string $provider, string $apiKey, string $file, string $color): void
    {
        requiresApiKey($apiKey);

        $response = Classification::of('A product photo uploaded by a customer.', [Image::fromPath(__DIR__ . '/../../Ai/Fixtures/Images/' . $file)])
            ->questions([
                'is_red' => new Boolean('Is the background of the image red?'),
                'color' => new Choice('What color is the background of the image?', [
                    'red' => null,
                    'green' => null,
                    'blue' => null,
                ]),
            ])->classify(provider: $provider);

        $this->assertSame($color === 'red', $response['is_red']->isTrue());
        $this->assertSame($color, $response['color']->choice);
    }

    /**
     * Provide each image format for every image classification service.
     */
    public static function imageProviders(): iterable
    {
        foreach (Providers::imageClassification() as $name => $provider) {
            yield $name . '-png' => [...$provider, 'red.png', 'red'];
            yield $name . '-jpeg' => [...$provider, 'blue.jpg', 'blue'];
        }
    }
}
